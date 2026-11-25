(() => {
  'use strict';

  const config = window.SGAConfig || {};
  const roots = document.querySelectorAll('[data-sga]');
  if (!roots.length || !config.ajax) return;

  /* ---------- utilitaires ---------- */
  const duration = (seconds) => {
    if (seconds === null || seconds === undefined) return '—';
    const s = Math.max(0, Math.round(seconds));
    const h = Math.floor(s / 3600);
    const m = Math.floor((s % 3600) / 60);
    return `${h} h ${String(m).padStart(2, '0')}`;
  };
  const localTime = (iso, withDate = true) => iso
    ? new Intl.DateTimeFormat('fr-FR', withDate
      ? { weekday: 'short', hour: '2-digit', minute: '2-digit' }
      : { hour: '2-digit', minute: '2-digit' }).format(new Date(iso))
    : '—';
  const km = (value) => (value === null || value === undefined ? '—' : `${Number(value).toFixed(1)} km`);
  const escapeHtml = (value) => String(value ?? '')
    .replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;').replaceAll("'", '&#039;');
  const norm = (value) => String(value ?? '')
    .normalize('NFD').replace(/[̀-ͯ]/g, '')
    .replace(/\s+/g, ' ').trim().toLocaleLowerCase('fr');
  const statusClass = (key) => ({
    finish: 'finish', racing: 'racing', stale: 'stale', short: 'short', dnf: 'dnf',
  }[key] || 'missing');
  const markerColor = (key) => ({
    finish: '#548235', racing: '#2f75b5', stale: '#8a94a3', short: '#7c3aed', dnf: '#b91c1c',
  }[key] || '#8a94a3');

  roots.forEach((root) => {
    const key = root.dataset.key;
    const club = root.dataset.club || '';
    const $ = (selector) => root.querySelector(selector);
    const el = {
      event: $('[data-sga-event]'), meta: $('[data-sga-meta]'), countdown: $('[data-sga-countdown]'),
      stats: $('[data-sga-stats]'), body: $('[data-sga-body]'), search: $('[data-sga-search]'),
      only: $('[data-sga-only]'), map: $('[data-sga-map]'), mapMeta: $('[data-sga-map-meta]'),
      form: $('[data-sga-follow-form]'), input: $('[data-sga-follow-input]'), datalist: $('[data-sga-datalist]'),
      hint: $('[data-sga-follow-hint]'), cards: $('[data-sga-cards]'),
      title: $('[data-sga-title]'), course: $('[data-sga-course]'), courseWrap: $('[data-sga-course-wrap]'),
    };
    /* ---------- parcours affiché ----------
       Par défaut celui du shortcode ; le visiteur peut en choisir un autre de la même épreuve
       (choix mémorisé sur son appareil). '' = parcours par défaut. */
    const courseKey = `sga:course:${key}`;
    let course = '';
    try { course = window.localStorage.getItem(courseKey) || ''; } catch (e) { /* navigation privée */ }
    const defaultCourse = root.dataset.course || '';
    if (course === defaultCourse) course = '';
    const params = () => `k=${key}${course ? `&p=${encodeURIComponent(course)}` : ''}`;
    const storageKey = () => (course ? `sga:follow:${key}:${course}` : `sga:follow:${key}`);
    let data = null;
    let rows = [];
    let route = null;
    let map = null;
    let markers = null;
    const markerById = new Map();
    let routeLayer = null;
    let remaining = 0;
    let timer = null;
    let generation = 0;

    /* ---------- coureurs suivis ----------
       Choisis dans la page par le visiteur et mémorisés sur son appareil (rien dans l'adresse).
       À défaut, valeur facultative du shortcode (coureur="…"). */
    const readStored = () => {
      try { return JSON.parse(window.localStorage.getItem(storageKey()) || 'null'); } catch (e) { return null; }
    };
    const initialFollowed = () => readStored()
      || (course ? [] : (root.dataset.defaultRiders || '').split(/[|,;]/).map((s) => s.trim()).filter(Boolean));
    let followed = initialFollowed();

    const saveFollowed = () => {
      try { window.localStorage.setItem(storageKey(), JSON.stringify(followed)); } catch (e) { /* navigation privée */ }
    };
    const isFollowed = (row) => followed.some((f) => norm(f) === norm(row.name) || (row.bib && norm(f) === norm(row.bib)));
    const findRow = (text) => {
      const q = norm(text).replace(/^#/, '');
      if (!q) return null;
      return rows.find((r) => norm(r.name) === q || norm(r.bib) === q || norm(`${r.name} (#${r.bib})`) === q)
        || rows.find((r) => norm(r.name).includes(q))
        || null;
    };
    const toggleFollow = (row) => {
      if (isFollowed(row)) {
        followed = followed.filter((f) => norm(f) !== norm(row.name) && norm(f) !== norm(row.bib));
      } else {
        followed = [...followed, row.name];
      }
      saveFollowed();
      render();
    };

    el.form.addEventListener('submit', (event) => {
      event.preventDefault();
      const row = findRow(el.input.value);
      if (!row) {
        el.hint.textContent = `Aucun concurrent « ${el.input.value} » sur ce parcours.`;
        el.hint.classList.add('is-error');
        return;
      }
      el.hint.classList.remove('is-error');
      el.hint.textContent = 'Votre choix est mémorisé sur cet appareil.';
      el.input.value = '';
      if (!isFollowed(row)) toggleFollow(row);
      focusRider(row.id);
    });

    /* ---------- carte ---------- */
    const initMap = async () => {
      if (!window.L) { el.mapMeta.textContent = 'La carte n’a pas pu être chargée.'; return; }
      const gen = generation;
      route = null;
      if (routeLayer) routeLayer.clearLayers();
      if (markers) markers.clearLayers();
      el.mapMeta.textContent = 'Chargement du tracé…';
      try {
        const response = await fetch(`${config.ajax}?action=sga_route&${params()}`, { credentials: 'same-origin' });
        const json = await response.json();
        if (gen !== generation) return;
        if (!response.ok || !Array.isArray(json.points)) throw new Error(json.message || 'Tracé indisponible');
        route = json;
        if (!map) {
          map = window.L.map(el.map, { scrollWheelZoom: false, preferCanvas: true });
          window.L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OpenStreetMap contributors', maxZoom: 18,
          }).addTo(map);
          routeLayer = window.L.layerGroup().addTo(map);
          markers = window.L.layerGroup().addTo(map);
        }
        const line = window.L.polyline(route.points.map((p) => [p[0], p[1]]), { color: '#ed7d31', opacity: .9, weight: 4 }).addTo(routeLayer);
        const first = route.points[0];
        const last = route.points[route.points.length - 1];
        window.L.circleMarker([first[0], first[1]], { radius: 7, color: '#fff', fillColor: '#17365d', fillOpacity: 1, weight: 2 })
          .bindTooltip(route.loop ? 'Départ / arrivée' : 'Départ').addTo(routeLayer);
        if (!route.loop) {
          window.L.circleMarker([last[0], last[1]], { radius: 7, color: '#fff', fillColor: '#111', fillOpacity: 1, weight: 2 })
            .bindTooltip('Arrivée').addTo(routeLayer);
        }
        map.fitBounds(line.getBounds(), { padding: [18, 18] });
        window.setTimeout(() => map.invalidateSize(), 0);
        renderMap();
      } catch (error) {
        if (gen === generation) el.mapMeta.textContent = `Tracé indisponible : ${error.message}`;
      }
    };

    const renderMap = () => {
      if (!map || !markers) return;
      markers.clearLayers();
      markerById.clear();
      let visible = 0;
      const ordered = [...rows].sort((a, b) => Number(isFollowed(a)) - Number(isFollowed(b)));
      ordered.forEach((r) => {
        if (!Number.isFinite(Number(r.latitude)) || r.status_key === 'dns') return;
        const featured = isFollowed(r);
        const marker = window.L.circleMarker([Number(r.latitude), Number(r.longitude)], {
          radius: featured ? 9 : 6, color: '#fff', weight: featured ? 3 : 2,
          fillColor: featured ? '#ed7d31' : markerColor(r.status_key), fillOpacity: .95,
        });
        marker.bindPopup(`<strong>#${escapeHtml(r.bib || '—')} · ${escapeHtml(r.name)}</strong><br>`
          + `${r.rank ? `${r.rank}<sup>e</sup> · ` : ''}${km(r.distance_km)} · ${escapeHtml(r.status)}<br>`
          + `<small>Position du ${localTime(r.last_position)}</small>`);
        marker.bindTooltip(escapeHtml(r.name), featured
          ? { permanent: true, direction: 'top', className: 'bx-gm__featured-label', offset: [0, -8] }
          : { direction: 'top' });
        marker.addTo(markers);
        markerById.set(r.id, marker);
        visible += 1;
      });
      el.mapMeta.textContent = `${route ? `${route.name} · ${Number(route.distance_km).toFixed(0)} km (${route.source})` : ''}`
        + ` · ${visible} position${visible > 1 ? 's' : ''} GPS`;
    };

    const focusRider = (id) => {
      const marker = markerById.get(id);
      if (!marker || !map) return;
      el.map.scrollIntoView({ behavior: 'smooth', block: 'center' });
      map.setView(marker.getLatLng(), Math.max(map.getZoom(), 12));
      marker.openPopup();
    };

    /* ---------- cartes des coureurs suivis ---------- */
    const renderCards = () => {
      const mine = rows.filter(isFollowed);
      const missing = followed.filter((f) => !rows.some((r) => norm(r.name) === norm(f) || norm(r.bib) === norm(f)));
      const ranked = rows.filter((r) => r.rank);
      el.cards.innerHTML = mine.map((r) => {
        const ahead = r.rank && r.rank > 1 ? ranked[r.rank - 2] : null;
        const behind = r.rank ? ranked[r.rank] : null;
        const gap = (other) => (other && other.distance_km !== null && r.distance_km !== null
          ? `${Math.abs(other.distance_km - r.distance_km).toFixed(1)} km` : '—');
        const live = r.status_key === 'racing' || r.status_key === 'stale';
        const leader = ranked[0];
        return `<article class="bx-gm__card bx-gm__card--${statusClass(r.status_key)}">
          <header>
            <div><strong>${escapeHtml(r.name)}</strong> <span>#${escapeHtml(r.bib || '—')}</span></div>
            <button type="button" class="bx-gm__unfollow" data-unfollow="${escapeHtml(r.id)}" aria-label="Ne plus suivre ${escapeHtml(r.name)}">×</button>
          </header>
          <div class="bx-gm__card-rank">${r.rank ? `${r.rank}<sup>e</sup><small>/ ${ranked.length}</small>` : '—'}</div>
          <span class="bx-gm__badge bx-gm__badge--${statusClass(r.status_key)}">${escapeHtml(r.status)}</span>
          <div class="bx-gm__progress" aria-hidden="true"><i style="width:${Math.min(100, r.progress || 0)}%"></i></div>
          <dl>
            <div><dt>Parcouru</dt><dd>${km(r.distance_km)}</dd></div>
            <div><dt>${r.status_key === 'finish' ? 'Temps' : 'Reste'}</dt><dd>${r.status_key === 'finish' ? duration(r.elapsed_seconds) : km(r.remaining_km)}</dd></div>
            <div><dt>Moyenne</dt><dd>${r.avg_speed_kmh ? `${r.avg_speed_kmh} km/h` : '—'}</dd></div>
            ${live ? `<div><dt>Devant</dt><dd>${ahead ? `${escapeHtml(ahead.name)} · ${gap(ahead)}` : '—'}</dd></div>
            <div><dt>Derrière</dt><dd>${behind ? `${escapeHtml(behind.name)} · ${gap(behind)}` : '—'}</dd></div>
            <div><dt>Leader</dt><dd>${leader && leader !== r ? (leader.status_key === 'finish' ? 'arrivé' : gap(leader)) : '—'}</dd></div>` : ''}
            ${r.status_key === 'finish' ? `<div><dt>Arrivée</dt><dd>${localTime(r.finish_time)}</dd></div>` : ''}
            ${r.skipped_km ? `<div><dt>Écart au tracé</dt><dd>≈ ${r.skipped_km} km non parcourus</dd></div>` : ''}
          </dl>
          <footer>
            <span>Position : ${localTime(r.last_position)}${r.delay_minutes > 5 && r.status_key !== 'finish' ? ` <em>(il y a ${Math.round(r.delay_minutes)} min)</em>` : ''}</span>
            ${Number.isFinite(Number(r.latitude)) ? `<button type="button" data-focus="${escapeHtml(r.id)}">Voir sur la carte</button>` : ''}
          </footer>
        </article>`;
      }).join('') + missing.map((name) => `<article class="bx-gm__card bx-gm__card--missing">
          <header><div><strong>${escapeHtml(name)}</strong></div>
          <button type="button" class="bx-gm__unfollow" data-unfollow-name="${escapeHtml(name)}" aria-label="Retirer">×</button></header>
          <p>Introuvable sur ce parcours${data ? ` (${escapeHtml(data.category)})` : ''}.</p></article>`).join('');
    };

    el.cards.addEventListener('click', (event) => {
      const target = event.target.closest('button');
      if (!target) return;
      if (target.dataset.focus) focusRider(target.dataset.focus);
      if (target.dataset.unfollow) {
        const row = rows.find((r) => r.id === target.dataset.unfollow);
        if (row) toggleFollow(row);
      }
      if (target.dataset.unfollowName) {
        followed = followed.filter((f) => f !== target.dataset.unfollowName);
        saveFollowed();
        render();
      }
    });

    /* ---------- tableau ---------- */
    const renderRows = () => {
      const query = norm(el.search.value);
      const filtered = rows.filter((r) => (!query || norm(`${r.name} ${r.bib}`).includes(query)) && (!el.only.checked || isFollowed(r)));
      if (!filtered.length) {
        el.body.innerHTML = `<tr><td colspan="10" class="bx-gm__empty">${rows.length ? 'Aucun concurrent trouvé.' : 'Aucun concurrent.'}</td></tr>`;
        return;
      }
      el.body.innerHTML = filtered.map((r) => {
        const featured = isFollowed(r);
        const medal = r.rank && r.rank <= 3 && r.status_key === 'finish' ? ` bx-gm__top bx-gm__top--${r.rank}` : '';
        return `<tr class="${medal}${featured ? ' bx-gm__featured' : ''}" data-id="${escapeHtml(r.id)}">
          <td data-label="Suivre"><button type="button" class="bx-gm__star" data-star="${escapeHtml(r.id)}" aria-pressed="${featured}" title="${featured ? 'Ne plus suivre' : 'Suivre'}">${featured ? '★' : '☆'}</button></td>
          <td data-label="Rang"><strong>${r.rank ?? '—'}</strong></td>
          <td data-label="Dossard">${escapeHtml(r.bib || '—')}</td>
          <td data-label="Concurrent"><strong>${escapeHtml(r.name)}</strong>${featured && club ? `<span class="bx-gm__club">${escapeHtml(club)}</span>` : ''}</td>
          <td data-label="Statut"><span class="bx-gm__badge bx-gm__badge--${statusClass(r.status_key)}">${escapeHtml(r.status)}</span></td>
          <td data-label="Distance">${km(r.distance_km)}${r.progress !== null ? `<small class="bx-gm__muted">${Math.round(r.progress)} %</small>` : ''}</td>
          <td data-label="Reste">${r.status_key === 'finish' ? '—' : km(r.remaining_km)}</td>
          <td data-label="Moyenne">${r.avg_speed_kmh ? `${r.avg_speed_kmh} km/h` : '—'}</td>
          <td data-label="Durée">${duration(r.elapsed_seconds)}</td>
          <td data-label="Dernière position">${localTime(r.last_position)}${r.delay_minutes > 5 && r.status_key !== 'finish' && r.status_key !== 'dns' ? `<small>il y a ${Math.round(r.delay_minutes)} min</small>` : ''}</td>
        </tr>`;
      }).join('');
    };

    el.body.addEventListener('click', (event) => {
      const star = event.target.closest('[data-star]');
      if (star) {
        const row = rows.find((r) => r.id === star.dataset.star);
        if (row) toggleFollow(row);
        return;
      }
      const tr = event.target.closest('tr[data-id]');
      if (tr) focusRider(tr.dataset.id);
    });
    el.search.addEventListener('input', renderRows);
    el.only.addEventListener('change', renderRows);

    const renderStats = (counts) => {
      const items = [
        ['Engagés', counts.total], ['Arrivés', counts.finished], ['En course', counts.racing + counts.stale],
        ['Écourtés', counts.short], ['Abandons', counts.dnf], ['Non-partants', counts.non_starters],
      ].filter(([label, value]) => value || ['Engagés', 'Arrivés', 'En course'].includes(label));
      el.stats.style.gridTemplateColumns = `repeat(${items.length}, 1fr)`;
      el.stats.innerHTML = items.map(([label, value]) => `<div><strong>${value ?? 0}</strong><span>${label}</span></div>`).join('');
    };

    const renderCourses = () => {
      const list = Array.isArray(data && data.courses) ? data.courses : [];
      if (list.length < 2) { el.courseWrap.hidden = true; return; }
      const current = data.category;
      el.course.innerHTML = list.map((c) => `<option value="${escapeHtml(c.key)}"${c.key === current ? ' selected' : ''}>`
        + `${escapeHtml(c.label)}${c.key === defaultCourse ? ' (par défaut)' : ''} · ${c.count} coureur${c.count > 1 ? 's' : ''}</option>`).join('');
      el.courseWrap.hidden = false;
    };

    const switchCourse = (value) => {
      course = value && value !== defaultCourse ? value : '';
      try {
        if (course) window.localStorage.setItem(courseKey, course);
        else window.localStorage.removeItem(courseKey);
      } catch (e) { /* navigation privée */ }
      generation += 1;
      window.clearTimeout(timer);
      data = null;
      rows = [];
      followed = initialFollowed();
      el.search.value = '';
      el.body.innerHTML = '<tr><td colspan="10" class="bx-gm__empty">Chargement…</td></tr>';
      el.cards.innerHTML = '';
      el.stats.innerHTML = '';
      el.meta.textContent = 'Chargement des positions…';
      root.classList.remove('bx-gm--error');
      initMap();
      load();
    };
    el.course.addEventListener('change', () => switchCourse(el.course.value));

    const render = () => {
      renderCards();
      renderRows();
      renderMap();
    };

    /* ---------- chargement ---------- */
    const load = async () => {
      const gen = generation;
      el.countdown.textContent = 'Actualisation…';
      let next = config.interval || 60000;
      try {
        const response = await fetch(`${config.ajax}?action=sga_data&${params()}&_=${Date.now()}`, { cache: 'no-store', credentials: 'same-origin' });
        const json = await response.json();
        if (gen !== generation) return;
        if (json.course === 'unknown' && course) { switchCourse(''); return; }
        if (!response.ok || json.error) throw new Error(json.message || `HTTP ${response.status}`);
        data = json;
        rows = Array.isArray(data.racers) ? data.racers : [];
        renderCourses();
        if (root.dataset.autoTitle) el.title.textContent = `Classement live ${data.category}`;
        el.event.textContent = data.event || 'Gravelman';
        const history = data.history < 100 ? ` · reconstitution de l’historique : ${data.history} %` : '';
        el.meta.textContent = `${data.label || data.category} · ${Number(data.route_km).toFixed(0)} km · départ ${localTime(data.start_at)} · relevé ${localTime(data.captured_at)}${history}`;
        el.datalist.innerHTML = rows.map((r) => `<option value="${escapeHtml(r.name)}">#${escapeHtml(r.bib)}</option>`).join('');
        renderStats(data.counts || {});
        render();
        root.classList.toggle('bx-gm--warning', Boolean(data.warning));
        root.classList.remove('bx-gm--error');
        if (data.history < 100) next = 8000;
        if (data.complete) next = 0;
      } catch (error) {
        if (gen !== generation) return;
        el.countdown.textContent = `Données indisponibles (${error.message})`;
        root.classList.add('bx-gm--error');
      }
      remaining = Math.round(next / 1000);
      if (next) timer = window.setTimeout(load, next);
      else el.countdown.textContent = 'Épreuve terminée · classement définitif';
    };

    window.setInterval(() => {
      if (remaining <= 0 || root.classList.contains('bx-gm--error')) return;
      remaining -= 1;
      el.countdown.textContent = `Actualisation dans ${remaining} s`;
    }, 1000);

    initMap();
    load();
  });
})();
