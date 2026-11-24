<?php
// php tests/trace.php <nom> : trace km par relevé pour un coureur (350G Orléans)
require __DIR__ . '/../suivi-gravelman-ameliore/includes/class-sga-core.php';
$cache = __DIR__ . '/.cache';
function json($u){global $cache;$f=$cache.'/'.md5($u);if(!is_file($f)){file_put_contents($f,file_get_contents($u));}return json_decode(file_get_contents($f),true);}
$B=SGA_Core::API_BASE;$id=256059;$key=$argv[2]??'350G';
$act=json("$B/stages/$id/activities/all?includeParticipants=true&includeDataSources=true");
$t=SGA_Core::dotvision_track_for_category(json("$B/stages/$id/gpx?preferredFormat=geojson"),$key);
$route=SGA_Core::build_route($t['name'],$t['points'],'dv');
$racers=array_filter(SGA_Core::select_racers($act,$key),fn($r)=>stripos($r['name'],$argv[1])!==false);
$start=strtotime('2026-10-16T04:00:00Z');$state=[];
$end=end($route['points']);
for($ts=$start;$ts<$start+(int)($argv[3]??20)*3600;$ts+=900){
 $p=SGA_Core::positions_from_snapshot(json("$B/data/snap/stage/$id?chunkNumber=".intdiv($ts,10)."&raceAgainstTime=false"));
 $state=SGA_Core::apply_snapshot($state,$racers,$p,$route,$start);
 foreach($state as $r){ $c=SGA_Core::candidates($route,$r['lat'],$r['lon']);
  printf("%s km=%7.1f off=%.2f dEnd=%.2f fin=%s cands=%s\n",gmdate('H:i',$ts),$r['km'],$r['off'],SGA_Core::haversine_km($r['lat'],$r['lon'],$end[0],$end[1]),$r['finish_ts']??'-',implode(',',array_map(fn($x)=>round($x['km']).'/'.round($x['off'],2),$c)));}
}
