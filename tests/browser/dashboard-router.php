<?php

// Isolated visual fixture: php -S 127.0.0.1:8001 tests/browser/dashboard-router.php
// No Laravel bootstrap, database, mailbox or real API connection.
if (PHP_SAPI !== 'cli-server' || !in_array($_SERVER['REMOTE_ADDR'], ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit;
}
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$public = realpath(__DIR__.'/../../public');
if (!str_starts_with($path, '/api/')) {
    $file = $path === '/' ? $public.'/index.html' : realpath($public.$path);
    if (!$file || !str_starts_with($file, $public.'/') || !is_file($file)) { http_response_code(404); exit; }
    $mime = ['html'=>'text/html', 'js'=>'text/javascript', 'css'=>'text/css', 'png'=>'image/png'];
    header('Content-Type: '.($mime[pathinfo($file, PATHINFO_EXTENSION)] ?? 'application/octet-stream'));
    $body = file_get_contents($file);
    if ($path === '/js/dashboard.js') {
        $body = str_replace('<h2>Dashboard</h2>', '<div><h2>Dashboard</h2><p style="color:#7C6F64;font-size:13px">Voorbeeldgegevens · geïsoleerde test</p></div>', $body);
    }
    echo $body;
    exit;
}
session_start();
header('Content-Type: application/json');
$me = ['id'=>'11111111-1111-4111-8111-111111111111', 'naam'=>'Dennis', 'rol'=>'admin', 'actief'=>true, 'kleur'=>'#3B82F6'];
$other = ['id'=>'22222222-2222-4222-8222-222222222222', 'naam'=>'Testcollega', 'rol'=>'lid', 'actief'=>true, 'kleur'=>'#73566f'];
$monday = new DateTimeImmutable('monday this week');
$date = fn(int $days) => $monday->modify('+'.$days.' days')->format('Y-m-d');
$projects = [
    ['id'=>'33333333-3333-4333-8333-333333333333', 'naam'=>'Najaarcampagne', 'beschrijving'=>'Campagnematerialen en content', 'status'=>'actief', 'kleur'=>'#ea484b', 'prioriteit'=>'normaal'],
    ['id'=>'44444444-4444-4444-8444-444444444444', 'naam'=>'Nieuwe producten', 'beschrijving'=>'Productintroducties', 'status'=>'actief', 'kleur'=>'#ea484b', 'prioriteit'=>'normaal'],
];
if (!isset($_SESSION['dashboard_fixture'])) {
    $tasks=[]; $notes=[]; $calendar=[];
    foreach (['Productteksten controleren','Nieuwsbrief klaarzetten','Beelden selecteren','Niet zichtbaar: collega'] as $i=>$title) {
        $tasks[]=['id'=>'55555555-5555-4555-8555-55555555555'.$i,'titel'=>$title,'status'=>['todo','bezig','review','todo'][$i], 'deadline'=>$date([0,3,4,0][$i]),'toegewezenen'=>[$i===3?$other:$me], 'project_id'=>$projects[$i%2]['id'], 'prioriteit'=>'normaal', 'beschrijving'=>'Fictieve testtaak'];
    }
    foreach (['Afspraken teamoverleg','Ideeën najaarscampagne','Niet zichtbaar: collega'] as $i=>$title) {
        $owner=$i===2?$other:$me;
        $notes[]=['id'=>'66666666-6666-4666-8666-66666666666'.$i,'titel'=>$title,'inhoud'=>'Fictieve notitie voor de dashboardtest.','aangemaakt_door'=>$owner['id'],'aangemaakt_door_naam'=>$owner['naam'],'created_at'=>$date(0).'T08:00:00','kleur'=>'#FEF3C7'];
    }
    foreach ([['Teamoverleg',0,9,'#73566f'],['Fotoshoot',1,10,'#008dd0'],['Nieuwsbrief',3,11,'#35a936']] as $i=>[$title,$day,$hour,$color]) {
        $calendar[]=['id'=>'77777777-7777-4777-8777-77777777777'.$i, 'titel'=>$title,'datum_start'=>$date($day).'T'.sprintf('%02d:00:00',$hour),'datum_eind'=>$date($day).'T'.sprintf('%02d:00:00',$hour+1),'type'=>'meeting','kleur'=>$color];
    }
    $_SESSION['dashboard_fixture']=['tasks'=>$tasks,'notes'=>$notes,'calendar'=>$calendar];
}
$fixture=&$_SESSION['dashboard_fixture'];
$method=$_SERVER['REQUEST_METHOD'];
$input=json_decode(file_get_contents('php://input'),true)??[];
if ($path==='/api/dashboard/preferences') {
    if ($method==='PUT') {
        $old=$_SESSION['dashboard_layout']??['revision'=>0];
        if (($input['revision']??-1)!==$old['revision']) {http_response_code(409); echo json_encode(['message'=>'Indeling gewijzigd']); exit;}
        $_SESSION['dashboard_layout']=['tiles'=>$input['tiles'],'revision'=>$old['revision']+1];
    }
    echo json_encode($_SESSION['dashboard_layout']??['tiles'=>null,'revision'=>0]); exit;
}
if (preg_match('~^/api/(tasks|notes|calendar)(?:/([^/]+))?$~',$path,$match)) {
    $kind=$match[1]; $id=$match[2]??null;
    if ($method==='POST') {
        $row=$input+['id'=>uniqid('test-',true),'created_at'=>date('c'),'aangemaakt_door'=>$me['id'],'aangemaakt_door_naam'=>$me['naam'],'status'=>'todo'];
        if ($kind==='tasks') $row['toegewezenen']=in_array($me['id'],$input['toegewezen_aan']??[],true)?[$me]:[];
        $fixture[$kind][]=$row; echo json_encode($row); exit;
    }
    if ($method==='PUT') {
        foreach ($fixture[$kind] as &$row) if ($row['id']===$id) { $row=array_merge($row,$input); echo json_encode($row); exit; }
    }
    $rows=$fixture[$kind];
    if ($kind==='tasks' && (isset($_GET['mine']) || isset($_GET['toegewezen_aan']))) $rows=array_values(array_filter($rows,fn($r)=>in_array($_GET['toegewezen_aan']??$me['id'],array_column($r['toegewezenen'],'id'),true)));
    if ($kind==='notes' && isset($_GET['mine'])) $rows=array_values(array_filter($rows,fn($r)=>$r['aangemaakt_door']===$me['id']));
    echo json_encode($rows); exit;
}
echo json_encode(match($path) {
    '/api/auth/me','/api/auth/login'=>$me,
    '/api/users'=>[$me,$other], '/api/projects'=>$projects,
    '/api/trunkrs/summary'=>['configured'=>false,'enabled'=>false,'report'=>null,'warnings'=>[]],
    '/api/auth/csrf'=>['ok'=>true],
    default=>[],
});
