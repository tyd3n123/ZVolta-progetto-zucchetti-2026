<?php
session_start();
require_once __DIR__ . "/../../../config/config.php";

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || !isset($_SESSION['id_utente'])) {
    header("Location: ../login.php"); exit();
}
$id_utente = (int)$_SESSION['id_utente'];

$stmt = $conn->prepare("SELECT u.nome, u.cognome, r.nome_ruolo AS ruolo FROM utenti u LEFT JOIN ruoli r ON u.id_ruolo = r.id_ruolo WHERE u.id_utente = ? LIMIT 1");
$stmt->bind_param("i", $id_utente); $stmt->execute();
$userInfo = $stmt->get_result()->fetch_assoc() ?? ['nome'=>'','cognome'=>'','ruolo'=>''];
$stmt->close();

$feedback = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'book') {
    $id_asset    = (int)($_POST['id_asset'] ?? 0);
    $data_inizio = $_POST['data_inizio'] ?? '';
    $data_fine   = $_POST['data_fine']   ?? '';
    if (!$id_asset || !$data_inizio || !$data_fine) {
        $feedback = ['type'=>'error','msg'=>'Compila tutti i campi prima di procedere.'];
    } elseif (strtotime($data_fine) <= strtotime($data_inizio)) {
        $feedback = ['type'=>'error','msg'=>'La data di fine deve essere successiva alla data di inizio.'];
    } else {
        $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM prenotazioni WHERE id_asset=? AND data_inizio<? AND data_fine>?");
        $stmt->bind_param("iss",$id_asset,$data_fine,$data_inizio); $stmt->execute();
        $overlap = $stmt->get_result()->fetch_assoc()['c']; $stmt->close();
        if ($overlap > 0) {
            $feedback = ['type'=>'error','msg'=>"L'ufficio è già occupato nel periodo selezionato."];
        } else {
            try {
                $conn->begin_transaction();
                $stmt = $conn->prepare("INSERT INTO prenotazioni (id_utente,id_asset,data_inizio,data_fine) VALUES (?,?,?,?)");
                $stmt->bind_param("iiss",$id_utente,$id_asset,$data_inizio,$data_fine);
                if(!$stmt->execute()) throw new Exception($stmt->error); $stmt->close();
                $stmt = $conn->prepare("UPDATE asset SET stato='Occupato' WHERE id_asset=?");
                $stmt->bind_param("i",$id_asset);
                if(!$stmt->execute()) throw new Exception($stmt->error); $stmt->close();
                $conn->commit();
                $feedback = ['type'=>'success','msg'=>'Ufficio prenotato con successo!'];
            } catch(Exception $e) {
                $conn->rollback();
                $feedback = ['type'=>'error','msg'=>'Errore: '.$e->getMessage()];
            }
        }
    }
}

$officeSpots = [];
$result = $conn->query("SELECT a.id_asset, a.codice_asset, a.stato, COALESCE(u.numero_ufficio,'-') AS numero_ufficio, COALESCE(u.piano,'-') AS piano, COALESCE(u.capacita,'-') AS capacita, COALESCE(u.telefono_interno,'-') AS telefono_interno FROM asset a LEFT JOIN ufficio_dettagli u ON u.id_asset=a.id_asset WHERE a.mappa='Sede' AND a.codice_asset LIKE 'Ufficio%' ORDER BY a.codice_asset");
if($result) while($row=$result->fetch_assoc()) $officeSpots[$row['id_asset']] = ['id'=>(int)$row['id_asset'],'name'=>$row['codice_asset'],'status'=>$row['stato'],'numero_ufficio'=>$row['numero_ufficio'],'piano'=>$row['piano'],'capacita'=>$row['capacita'],'telefono_interno'=>$row['telefono_interno']];

$userBookings = [];
$stmt = $conn->prepare("SELECT p.id_prenotazione,p.data_inizio,p.data_fine,a.codice_asset,a.id_asset FROM prenotazioni p JOIN asset a ON p.id_asset=a.id_asset WHERE p.id_utente=? AND a.mappa='Sede' AND a.codice_asset LIKE 'Ufficio%' AND p.data_fine>=NOW() ORDER BY p.data_inizio ASC");
$stmt->bind_param("i",$id_utente); $stmt->execute();
$result=$stmt->get_result(); while($row=$result->fetch_assoc()) $userBookings[]=$row; $stmt->close();

$occupiedSlotsByAsset = [];
$result = $conn->query("SELECT p.id_asset,p.data_inizio,p.data_fine FROM prenotazioni p JOIN asset a ON p.id_asset=a.id_asset WHERE a.mappa='Sede' AND a.codice_asset LIKE 'Ufficio%' AND p.data_fine>=NOW() ORDER BY p.data_inizio ASC");
if($result) while($row=$result->fetch_assoc()) $occupiedSlotsByAsset[$row['id_asset']][]=['inizio'=>$row['data_inizio'],'fine'=>$row['data_fine']];

$totalOffices = count($officeSpots);
$availCount   = count(array_filter($officeSpots, fn($s) => strtolower($s['status'])!=='occupato'));
$occCount     = $totalOffices - $availCount;
$officeList   = array_values($officeSpots);
$reopenAssetId = (!empty($_POST['id_asset'])) ? (int)$_POST['id_asset'] : 0;
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Uffici | Northstar</title>
<link rel="stylesheet" href="../dashboard/dashboard.css">
<link rel="stylesheet" href="./uffici.css">
</head>
<body>

<header class="header">
  <div class="header-left">
    <h1>Northstar</h1>
    <nav class="header-breadcrumb">
      <a href="../dashboard/index.php">Dashboard</a>
      <span class="bc-sep">/</span>
      <span class="bc-current">Uffici</span>
    </nav>
  </div>
  <div class="uf-user-pill">
    <?= htmlspecialchars($userInfo['nome'].' '.$userInfo['cognome']) ?>
    <span class="uf-role"><?= htmlspecialchars($userInfo['ruolo']) ?></span>
  </div>
</header>

<div class="uf-page">

  <div class="uf-title-row">
    <div>
      <h2 class="uf-page-title">🏢 Uffici</h2>
      <p class="uf-page-sub">Clicca su una postazione nella mappa per prenotarla</p>
    </div>
    <div class="uf-stats-row">
      <span class="uf-stat-chip uf-stat-chip--total">🏢 <?= $totalOffices ?> uffici</span>
      <span class="uf-stat-chip uf-stat-chip--avail">✓ <?= $availCount ?> liberi</span>
      <?php if($occCount>0):?><span class="uf-stat-chip uf-stat-chip--occ">✗ <?= $occCount ?> occupati</span><?php endif;?>
    </div>
  </div>

  <?php if($feedback):?>
  <div class="uf-feedback uf-feedback--<?= $feedback['type'] ?>">
    <?= $feedback['type']==='success'?'✅':'⚠️' ?> <?= htmlspecialchars($feedback['msg']) ?>
  </div>
  <?php endif;?>

  <!-- ══ MAPPA + PANNELLO ══════════════════════════════ -->
  <div class="uf-map-wrap" id="uf-map-wrap">

    <div class="uf-canvas-zone" id="uf-canvas-zone">
      <canvas id="floorCanvas"></canvas>
      <div class="uf-map-legend">
        <div class="uf-leg-item"><span class="uf-leg-dot" style="background:#4ade80"></span>Disponibile</div>
        <div class="uf-leg-item"><span class="uf-leg-dot" style="background:#f87171"></span>Occupato</div>
        <div class="uf-leg-item"><span class="uf-leg-dot" style="background:#94a3b8"></span>Zona comune</div>
      </div>
    </div>

    <div class="uf-side-panel" id="uf-side-panel">
      <div class="uf-panel-inner">
        <div class="uf-panel-top">
          <div>
            <h3 class="uf-panel-name" id="p-name">—</h3>
            <div id="p-badge" style="margin-top:6px"></div>
          </div>
          <button class="uf-panel-close" onclick="closePanel()">✕</button>
        </div>
        <div class="uf-info-grid" id="p-info"></div>
        <div class="uf-psep"></div>
        <p class="uf-section-lbl">Periodi occupati</p>
        <div id="p-slots"></div>
        <div class="uf-psep"></div>
        <p class="uf-section-lbl">Nuova prenotazione</p>
        <form method="POST" id="booking-form">
          <input type="hidden" name="action" value="book">
          <input type="hidden" name="id_asset" id="f-asset-id" value="">
          <div class="uf-field">
            <label class="uf-field-lbl">Data inizio</label>
            <input class="uf-field-input" type="datetime-local" name="data_inizio" id="f-start"
                   value="<?= htmlspecialchars($_POST['data_inizio']??'') ?>" required onchange="updateDur()">
          </div>
          <div class="uf-field">
            <label class="uf-field-lbl">Data fine</label>
            <input class="uf-field-input" type="datetime-local" name="data_fine" id="f-end"
                   value="<?= htmlspecialchars($_POST['data_fine']??'') ?>" required onchange="updateDur()">
          </div>
          <div id="f-dur" class="uf-dur-preview" style="display:none"></div>
          <div id="f-err" class="uf-form-error" style="display:none"></div>
          <button type="submit" class="uf-submit-btn" id="f-btn">Conferma prenotazione</button>
        </form>
      </div>
    </div>

  </div>

  <!-- Prenotazioni attive -->
  <div class="uf-bookings-strip">
    <div class="uf-strip-header">
      <p class="uf-strip-title">Le tue prenotazioni attive</p>
      <span class="uf-count-badge"><?= count($userBookings) ?></span>
    </div>
    <?php if(empty($userBookings)):?>
    <div class="uf-empty"><span>🏢</span><p>Nessuna prenotazione ufficio attiva</p></div>
    <?php else:?>
    <div class="uf-bookings-list">
      <?php foreach($userBookings as $b):
        $start=new DateTime($b['data_inizio']); $end=new DateTime($b['data_fine']); $now=new DateTime();
        $isActive=$start<=$now&&$end>=$now;
        $sClass=$isActive?'uf-status--now':'uf-status--future';
        $sLabel=$isActive?'In corso':'Programmato';
        $diff=$start->diff($end); $days=$diff->days; $hours=$diff->h;
        $durStr=$days>0?"{$days}g {$hours}h":"{$hours}h {$diff->i}m";
      ?>
      <div class="uf-booking-item">
        <div class="uf-booking-top">
          <span class="uf-asset-pill"><?= htmlspecialchars($b['codice_asset']) ?></span>
          <span class="uf-status-pill <?= $sClass ?>"><?= $sLabel ?></span>
          <span class="uf-dur"><?= $durStr ?></span>
        </div>
        <div class="uf-booking-dates">
          <?= date('d/m/Y H:i',strtotime($b['data_inizio'])) ?> <span class="uf-arrow">→</span> <?= date('d/m/Y H:i',strtotime($b['data_fine'])) ?>
        </div>
      </div>
      <?php endforeach;?>
    </div>
    <?php endif;?>
  </div>

</div>

<script>
const officeList    = <?= json_encode($officeList) ?>;
const occupiedSlots = <?= json_encode($occupiedSlotsByAsset) ?>;
const reopenAssetId = <?= $reopenAssetId ?>;

const canvas = document.getElementById('floorCanvas');
const ctx    = canvas.getContext('2d');
const zone   = document.getElementById('uf-canvas-zone');
const isDark = matchMedia('(prefers-color-scheme:dark)').matches;

const T = {
  wall:'#c8c4bb',wallFill:'#e8e4dc',floor:'#f4f1ec',wood:'#c8a87a',woodDark:'#b89060',
  glass:'rgba(180,220,255,0.45)',glassBrd:'rgba(150,200,255,0.7)',
  desk:'#d6cabc',deskTop:'#e8ddd0',monitor:'#2d2d2d',screen:'#0d2040',chair:'#64748b',
  avail:'#4ade80',occ:'#f87171',sel:'#818cf8',selGlow:'rgba(129,140,248,0.35)',
  plant:'#4ade80',plantDrk:'#16a34a',rug0:'rgba(129,140,248,0.08)',
};
if(isDark) Object.assign(T,{
  wall:'#3a3832',wallFill:'#292722',floor:'#1e1c18',wood:'#3d3020',woodDark:'#2e2418',
  glass:'rgba(120,160,210,0.18)',glassBrd:'rgba(120,160,210,0.35)',
  desk:'#3a3530',deskTop:'#4a4035',monitor:'#2a2a2a',rug0:'rgba(129,140,248,0.12)',
});

const MAP_W=860, MAP_H=520;
let selectedId=null, spots=[];

function getOffset(){return{ox:Math.max(0,(canvas.width-MAP_W)/2),oy:Math.max(0,(canvas.height-MAP_H)/2)};}
function resize(){canvas.width=zone.clientWidth;canvas.height=zone.clientHeight;draw();}
function rr(x,y,w,h,r){ctx.beginPath();ctx.moveTo(x+r,y);ctx.arcTo(x+w,y,x+w,y+h,r);ctx.arcTo(x+w,y+h,x,y+h,r);ctx.arcTo(x,y+h,x,y,r);ctx.arcTo(x,y,x+w,y,r);ctx.closePath();}

function draw(){
  const {ox,oy}=getOffset();
  ctx.clearRect(0,0,canvas.width,canvas.height);
  ctx.fillStyle=T.floor; ctx.fillRect(0,0,canvas.width,canvas.height);
  drawShell(ox,oy); drawAreas(ox,oy); drawFurniture(ox,oy); drawDesks(ox,oy); drawDeco(ox,oy);
}

function drawShell(ox,oy){
  rr(ox+10,oy+10,MAP_W-20,MAP_H-20,16); ctx.fillStyle=T.wallFill; ctx.fill();
  ctx.strokeStyle=T.wall; ctx.lineWidth=10; ctx.stroke(); ctx.lineWidth=1;
  ctx.save(); ctx.clip();
  ctx.fillStyle=isDark?'#1a1816':'#ede8e0'; ctx.fillRect(ox+15,oy+15,MAP_W-30,MAP_H-30);
  ctx.strokeStyle=isDark?'rgba(255,255,255,0.04)':'rgba(0,0,0,0.05)'; ctx.lineWidth=0.5;
  for(let yy=oy+15;yy<oy+MAP_H-15;yy+=24){const off=((yy/24)%2)*60;for(let xx=ox+15-off;xx<ox+MAP_W-15;xx+=120){ctx.beginPath();ctx.rect(xx,yy,119,23);ctx.stroke();}}
  ctx.restore();
  [[ox+10,oy+80,8,80],[ox+10,oy+200,8,80],[ox+10,oy+330,8,80],[ox+MAP_W-18,oy+80,8,80],[ox+MAP_W-18,oy+200,8,80],[ox+MAP_W-18,oy+330,8,80]].forEach(([x,y,w,h])=>{ctx.fillStyle=T.glass;ctx.fillRect(x,y,w,h);ctx.strokeStyle=T.glassBrd;ctx.lineWidth=1;ctx.strokeRect(x,y,w,h);ctx.lineWidth=0.5;ctx.beginPath();ctx.moveTo(x+w/2,y);ctx.lineTo(x+w/2,y+h);ctx.stroke();ctx.beginPath();ctx.moveTo(x,y+h/2);ctx.lineTo(x+w,y+h/2);ctx.stroke();});
  [[ox+150,oy+10,100,8],[ox+400,oy+10,100,8],[ox+600,oy+10,100,8]].forEach(([x,y,w,h])=>{ctx.fillStyle=T.glass;ctx.fillRect(x,y,w,h);ctx.strokeStyle=T.glassBrd;ctx.lineWidth=1;ctx.strokeRect(x,y,w,h);ctx.lineWidth=0.5;ctx.beginPath();ctx.moveTo(x+w/3,y);ctx.lineTo(x+w/3,y+h);ctx.stroke();ctx.beginPath();ctx.moveTo(x+2*w/3,y);ctx.lineTo(x+2*w/3,y+h);ctx.stroke();});
}

function drawAreas(ox,oy){
  [{x:ox+20,y:oy+20,w:190,h:160,c:'rgba(99,102,241',l:'SALA RIUNIONI',lx:ox+115,ly:oy+38},{x:ox+20,y:oy+340,w:160,h:150,c:'rgba(20,184,166',l:'ZONA BREAK',lx:ox+100,ly:oy+358},{x:ox+MAP_W-175,y:oy+20,w:155,h:100,c:'rgba(148,163,184',l:'SERVIZI',lx:ox+MAP_W-97,ly:oy+38}].forEach(z=>{
    ctx.fillStyle=z.c+',0.09)'; rr(z.x,z.y,z.w,z.h,8); ctx.fill();
    ctx.strokeStyle=z.c+',0.3)'; ctx.lineWidth=1; rr(z.x,z.y,z.w,z.h,8); ctx.stroke();
    ctx.fillStyle=z.c+',0.65)'; ctx.font='500 11px system-ui'; ctx.textAlign='center'; ctx.textBaseline='middle'; ctx.fillText(z.l,z.lx,z.ly);
  });
  ctx.strokeStyle=T.wall; ctx.lineWidth=2; ctx.beginPath(); ctx.moveTo(ox+MAP_W-175+78,oy+20); ctx.lineTo(ox+MAP_W-175+78,oy+120); ctx.stroke();
  ctx.fillStyle=T.rug0; rr(ox+280,oy+180,300,140,6); ctx.fill();
  ctx.strokeStyle=isDark?'rgba(129,140,248,0.2)':'rgba(129,140,248,0.15)'; ctx.lineWidth=0.5; rr(ox+280,oy+180,300,140,6); ctx.stroke();
  rr(ox+290,oy+190,280,120,4); ctx.stroke();
}

function drawFurniture(ox,oy){
  ctx.fillStyle=T.wood; ctx.beginPath(); ctx.ellipse(ox+115,oy+95,72,40,0,0,Math.PI*2); ctx.fill();
  ctx.strokeStyle=T.woodDark; ctx.lineWidth=1; ctx.stroke();
  ctx.fillStyle=isDark?'rgba(255,255,255,0.06)':'rgba(255,255,255,0.3)'; ctx.beginPath(); ctx.ellipse(ox+105,oy+87,46,24,-0.3,0,Math.PI*2); ctx.fill();
  [[ox+115,oy+47],[ox+115,oy+143],[ox+57,oy+78],[ox+57,oy+112],[ox+173,oy+78],[ox+173,oy+112],[ox+82,oy+47],[ox+148,oy+47]].forEach(([cx,cy])=>{ctx.fillStyle=T.chair;rr(cx-9,cy-7,18,14,3);ctx.fill();});
  ctx.fillStyle=isDark?'#2a2822':'#d1c8b8'; rr(ox+25,oy+345,150,35,4); ctx.fill();
  ctx.strokeStyle=T.woodDark; ctx.lineWidth=0.5; rr(ox+25,oy+345,150,35,4); ctx.stroke();
  ctx.fillStyle=isDark?'#1e1c18':'#b8b0a0'; rr(ox+33,oy+351,32,22,3); ctx.fill();
  ctx.fillStyle=isDark?'#2a2822':'#c0b8a8'; rr(ox+75,oy+351,25,24,3); ctx.fill();
  ctx.fillStyle=isDark?'#0d2040':'#1a3a6a'; rr(ox+79,oy+354,17,10,2); ctx.fill();
  ctx.fillStyle=isDark?'#1a1816':'#b8b0a0'; rr(ox+110,oy+351,28,22,3); ctx.fill();
  ctx.fillStyle=isDark?'#0d2040':'#1a3a6a'; rr(ox+113,oy+354,16,12,2); ctx.fill();
  ctx.fillStyle=isDark?'#3a3530':'#b8b0a0'; rr(ox+35,oy+415,120,30,5); ctx.fill();
  ctx.fillStyle=isDark?'#2e2a26':'#a8a098'; rr(ox+35,oy+395,120,22,5); ctx.fill();
  ctx.strokeStyle=isDark?'#4a4540':'#988e82'; ctx.lineWidth=0.5; rr(ox+35,oy+395,120,50,5); ctx.stroke();
  ctx.fillStyle=isDark?'rgba(99,102,241,0.3)':'rgba(99,102,241,0.25)'; rr(ox+45,oy+399,46,13,3); ctx.fill(); rr(ox+99,oy+399,46,13,3); ctx.fill();
  ctx.fillStyle=T.wood; rr(ox+75,oy+450,60,35,5); ctx.fill(); ctx.strokeStyle=T.woodDark; ctx.lineWidth=0.5; rr(ox+75,oy+450,60,35,5); ctx.stroke();
  [[ox+MAP_W-168,oy+40],[ox+MAP_W-95,oy+40]].forEach(([x,y])=>{ctx.fillStyle=isDark?'#2a2822':'#f0ece4';ctx.strokeStyle=isDark?'#4a4540':'#d0c8b8';ctx.lineWidth=0.5;rr(x+4,y,30,40,8);ctx.fill();ctx.stroke();ctx.beginPath();ctx.ellipse(x+19,y+30,13,10,0,0,Math.PI*2);ctx.fill();ctx.stroke();ctx.fillStyle=isDark?'#2a2822':'#e8e4dc';rr(x+4,y+44,30,22,4);ctx.fill();ctx.fillStyle=isDark?'#1e1c18':'#d0c8b8';rr(x+8,y+48,22,14,3);ctx.fill();});
}

function drawPlant(x,y,r){ctx.fillStyle=T.plantDrk;ctx.beginPath();ctx.arc(x,y,r+1,0,Math.PI*2);ctx.fill();ctx.fillStyle=T.plant;[[x-r*.4,y-r*.3,r*.7],[x+r*.4,y-r*.3,r*.7],[x,y-r*.5,r*.8]].forEach(([px,py,pr])=>{ctx.beginPath();ctx.arc(px,py,pr,0,Math.PI*2);ctx.fill();});ctx.fillStyle=isDark?'#5a4a3a':'#c8a87a';rr(x-r*.5,y+r*.3,r,r*.7,2);ctx.fill();}

function drawDeco(ox,oy){
  drawPlant(ox+MAP_W-40,oy+MAP_H-40,18); drawPlant(ox+240,oy+MAP_H-35,14); drawPlant(ox+MAP_W-40,oy+300,14); drawPlant(ox+220,oy+20,12);
  ctx.fillStyle=isDark?'#3a3530':'#ffffff'; ctx.strokeStyle=isDark?'#4a4540':'#d0c8b8'; ctx.lineWidth=1; ctx.beginPath(); ctx.arc(ox+MAP_W/2,oy+25,16,0,Math.PI*2); ctx.fill(); ctx.stroke();
  ctx.strokeStyle=isDark?'#8a8480':'#3d3830'; ctx.lineWidth=1.5; ctx.beginPath(); ctx.moveTo(ox+MAP_W/2,oy+25); ctx.lineTo(ox+MAP_W/2,oy+15); ctx.stroke();
  ctx.lineWidth=1; ctx.beginPath(); ctx.moveTo(ox+MAP_W/2,oy+25); ctx.lineTo(ox+MAP_W/2+7,oy+28); ctx.stroke();
}

const DESK_W=62,DESK_H=40,SEAT_R=13;
const deskDefs=[{idx:0,x:250,y:45,rot:'up'},{idx:1,x:340,y:45,rot:'up'},{idx:2,x:490,y:45,rot:'up'},{idx:3,x:580,y:45,rot:'up'},{idx:4,x:225,y:200,rot:'right'},{idx:5,x:225,y:270,rot:'right'},{idx:6,x:590,y:200,rot:'left'},{idx:7,x:590,y:270,rot:'left'},{idx:8,x:310,y:380,rot:'up'},{idx:9,x:395,y:380,rot:'up'},{idx:10,x:490,y:380,rot:'up'},{idx:11,x:575,y:380,rot:'up'}];

function drawDesks(ox,oy){
  spots=[];
  deskDefs.forEach(d=>{
    const office=officeList[d.idx]||null, rot=d.rot||'up';
    const isOcc=office&&office.status.toLowerCase()==='occupato', isSel=office&&office.id==selectedId;
    const dx=ox+d.x,dy=oy+d.y;
    let hx=dx,hy=dy,hw=DESK_W,hh=DESK_H+SEAT_R*2+8;
    if(rot==='right'||rot==='left'){hw=DESK_H+SEAT_R*2+8;hh=DESK_W;}
    if(office) spots.push({id:office.id,office,hx,hy,hw,hh});
    if(isSel){ctx.shadowColor=T.sel;ctx.shadowBlur=18;}
    drawDesk(dx,dy,rot,isOcc,isSel,office);
    if(isSel){ctx.shadowColor='transparent';ctx.shadowBlur=0;}
  });
}

function drawDesk(dx,dy,rot,isOcc,isSel,office){
  const sc=isOcc?T.occ:T.avail;
  let sx,sy,deskX,deskY,dw,dh;
  if(rot==='up'){deskX=dx;deskY=dy+SEAT_R*2+4;sx=dx+DESK_W/2;sy=dy+SEAT_R;dw=DESK_W;dh=DESK_H;}
  else if(rot==='down'){deskX=dx;deskY=dy;sx=dx+DESK_W/2;sy=dy+DESK_H+SEAT_R+4;dw=DESK_W;dh=DESK_H;}
  else if(rot==='right'){deskX=dx+SEAT_R*2+4;deskY=dy+(DESK_W-DESK_H)/2;sx=dx+SEAT_R;sy=dy+DESK_W/2;dw=DESK_H;dh=DESK_W;}
  else{deskX=dx;deskY=dy+(DESK_W-DESK_H)/2;sx=dx+DESK_H+SEAT_R+4;sy=dy+DESK_W/2;dw=DESK_H;dh=DESK_W;}
  ctx.fillStyle=isSel?T.selGlow:(isOcc?'rgba(248,113,113,0.2)':'rgba(74,222,128,0.2)'); ctx.beginPath(); ctx.arc(sx,sy,SEAT_R+5,0,Math.PI*2); ctx.fill();
  ctx.fillStyle=sc; ctx.strokeStyle='#ffffff'; ctx.lineWidth=2; ctx.beginPath(); ctx.arc(sx,sy,SEAT_R,0,Math.PI*2); ctx.fill(); ctx.stroke();
  if(isSel){ctx.strokeStyle=T.sel;ctx.lineWidth=2.5;ctx.beginPath();ctx.arc(sx,sy,SEAT_R+4,0,Math.PI*2);ctx.stroke();}
  if(office){const code=office.name.replace(/Ufficio\s?/,'').substring(0,3);ctx.fillStyle='#ffffff';ctx.font='500 9px system-ui';ctx.textAlign='center';ctx.textBaseline='middle';ctx.fillText(code,sx,sy);}
  ctx.fillStyle=isDark?'rgba(0,0,0,0.4)':'rgba(0,0,0,0.08)'; rr(deskX+2,deskY+2,dw,dh,5); ctx.fill();
  ctx.fillStyle=T.deskTop; rr(deskX,deskY,dw,dh,5); ctx.fill();
  ctx.strokeStyle=T.desk; ctx.lineWidth=0.8; rr(deskX,deskY,dw,dh,5); ctx.stroke();
  const mx=deskX+dw/2,my=deskY+dh/2;
  ctx.fillStyle=T.monitor; rr(mx-10,my-8,20,13,2); ctx.fill();
  ctx.fillStyle=T.screen; rr(mx-8.5,my-6.5,17,9,1); ctx.fill();
  ctx.fillStyle=T.monitor; ctx.fillRect(mx-2,my+5,4,4); ctx.fillRect(mx-5,my+8,10,2);
  ctx.fillStyle=isDark?'#7c6a50':'#c8a87a'; ctx.beginPath(); ctx.arc(deskX+dw-10,deskY+dh-8,4,0,Math.PI*2); ctx.fill();
  ctx.fillStyle=isDark?'#0d1520':'#3d2010'; ctx.beginPath(); ctx.arc(deskX+dw-10,deskY+dh-8,2.5,0,Math.PI*2); ctx.fill();
  ctx.fillStyle=isDark?'rgba(255,255,255,0.07)':'rgba(0,0,0,0.06)'; rr(deskX+5,deskY+5,14,10,1); ctx.fill(); rr(deskX+7,deskY+7,14,10,1); ctx.fill();
}

canvas.addEventListener('click',function(e){
  const r=canvas.getBoundingClientRect(),mx=e.clientX-r.left,my=e.clientY-r.top;
  let hit=null; for(const s of spots){if(mx>=s.hx&&mx<=s.hx+s.hw&&my>=s.hy&&my<=s.hy+s.hh){hit=s;break;}}
  if(hit){selectedId=hit.id;openPanel(hit.office);}else{selectedId=null;closePanel();}
  draw();
});
canvas.addEventListener('mousemove',function(e){
  const r=canvas.getBoundingClientRect(),mx=e.clientX-r.left,my=e.clientY-r.top;
  canvas.style.cursor=spots.some(s=>mx>=s.hx&&mx<=s.hx+s.hw&&my>=s.hy&&my<=s.hy+s.hh)?'pointer':'default';
});
document.addEventListener('keydown',e=>{if(e.key==='Escape'){selectedId=null;closePanel();draw();}});

function openPanel(office){
  const isOcc=office.status.toLowerCase()==='occupato';
  document.getElementById('p-name').textContent=office.name;
  document.getElementById('p-badge').innerHTML=`<span class="uf-badge ${isOcc?'uf-badge--occ':'uf-badge--avail'}">${isOcc?'Occupato':'Disponibile'}</span>`;
  document.getElementById('p-info').innerHTML=`<div class="uf-info-tile"><div class="uf-tile-lbl">N° Ufficio</div><div class="uf-tile-val">${office.numero_ufficio}</div></div><div class="uf-info-tile"><div class="uf-tile-lbl">Piano</div><div class="uf-tile-val">${office.piano}</div></div><div class="uf-info-tile"><div class="uf-tile-lbl">Capacità</div><div class="uf-tile-val">${office.capacita} pers.</div></div><div class="uf-info-tile"><div class="uf-tile-lbl">Tel. interno</div><div class="uf-tile-val">${office.telefono_interno}</div></div>`;
  const sl=occupiedSlots[office.id]||[];
  document.getElementById('p-slots').innerHTML=sl.length>0?sl.map(s=>`<div class="uf-slot-item"><span class="uf-slot-dot"></span>${fmtDate(s.inizio)} → ${fmtDate(s.fine)}</div>`).join(''):'<div class="uf-slot-free">✓ Nessun periodo occupato</div>';
  document.getElementById('f-asset-id').value=office.id;
  document.getElementById('uf-side-panel').classList.add('open');
  document.getElementById('uf-map-wrap').classList.add('panel-open');
  updateDur();
}

function closePanel(){
  document.getElementById('uf-side-panel').classList.remove('open');
  document.getElementById('uf-map-wrap').classList.remove('panel-open');
}

function updateDur(){
  const s=document.getElementById('f-start').value,e=document.getElementById('f-end').value;
  const el=document.getElementById('f-dur'),err=document.getElementById('f-err'),btn=document.getElementById('f-btn');
  if(!s||!e){el.style.display='none';err.style.display='none';btn.disabled=false;return;}
  const ms=new Date(e)-new Date(s);
  if(ms<=0){el.style.display='none';err.style.display='';err.textContent="⚠ La data fine deve essere successiva all'inizio";btn.disabled=true;return;}
  err.style.display='none';btn.disabled=false;
  const d=Math.floor(ms/86400000),h=Math.floor((ms%86400000)/3600000),m=Math.floor((ms%3600000)/60000);
  const parts=[];if(d>0)parts.push(d+'g');if(h>0)parts.push(h+'h');if(m>0)parts.push(m+'m');
  el.style.display='';el.textContent='⏱ Durata: '+parts.join(' ');
}

function fmtDate(s){return new Date(s).toLocaleString('it-IT',{day:'2-digit',month:'2-digit',year:'numeric',hour:'2-digit',minute:'2-digit'});}

const ro=new ResizeObserver(resize); ro.observe(zone); resize(); updateDur();
if(reopenAssetId){const off=officeList.find(o=>o.id==reopenAssetId);if(off){selectedId=reopenAssetId;setTimeout(()=>{openPanel(off);draw();},100);}}
</script>
</body>
</html>