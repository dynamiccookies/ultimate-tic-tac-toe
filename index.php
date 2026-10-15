<?php
/** Ultimate Tic Tac Toe v1.3.0 — PHP 8+, no dependencies. */
declare(strict_types=1);
if (defined('UTTT_TEST')) { return; }
ini_set('session.use_strict_mode', '1');
session_name('ultimate_ttt');
session_start(['cookie_httponly'=>true,'cookie_samesite'=>'Lax','cookie_secure'=>!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
$_SESSION['uttt_csrf'] ??= bin2hex(random_bytes(24));
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
// Online rooms live outside the web root. All reads and writes hold an exclusive lock.
function onlineWinner(array $cells): string {
    foreach([[0,1,2],[3,4,5],[6,7,8],[0,3,6],[1,4,7],[2,5,8],[0,4,8],[2,4,6]] as [$a,$b,$c])
        if(in_array($cells[$a],['X','O'],true)&&$cells[$a]===$cells[$b]&&$cells[$a]===$cells[$c]) return $cells[$a];
    return in_array('',$cells,true)?'':'D';
}
function onlineReplay(array $moves,string $type): array {
    $s=['boards'=>array_fill(0,9,array_fill(0,9,'')),'won'=>array_fill(0,9,''),'target'=>-1,'turn'=>'X','result'=>''];
    foreach($moves as $m){
        if(!is_array($m)||count($m)!==2||!is_int($m[0]??null)||!is_int($m[1]??null))throw new RuntimeException('Invalid move.');
        [$b,$c]=$m;
        if($s['result']||$b<0||$b>8||$c<0||$c>8||($type==='regular'&&$b!==0)||$s['won'][$b]||$s['boards'][$b][$c]||($s['target']!==-1&&$s['target']!==$b))throw new RuntimeException('That square is not playable.');
        $s['boards'][$b][$c]=$s['turn'];$s['won'][$b]=onlineWinner($s['boards'][$b]);
        $s['result']=$type==='regular'?$s['won'][0]:onlineWinner($s['won']);
        $s['target']=$type==='regular'?0:($s['won'][$c]?-1:$c);$s['turn']=$s['turn']==='X'?'O':'X';
    }
    return $s;
}
function onlineRequest(array $in): array {
    $action=$in['action'];$now=time();
    $_SESSION['uttt_player']??=bin2hex(random_bytes(24));$player=$_SESSION['uttt_player'];
    // Bound room creation and code guesses per browser session.
    if(in_array($action,['online_host','online_join'],true)){
        $attempts=array_values(array_filter($_SESSION['uttt_attempts']??[],fn($t)=>$t>$now-60));
        if(count($attempts)>=12)throw new RuntimeException('Too many room requests. Wait a minute and try again.');
        $attempts[]=$now;$_SESSION['uttt_attempts']=$attempts;
    }
    $dir=sys_get_temp_dir().'/uttt-rooms-'.substr(hash('sha256',__FILE__),0,20);
    if(!is_dir($dir)&&!@mkdir($dir,0700,true)&&!is_dir($dir))throw new RuntimeException('Online play needs writable temporary storage on this host.');
    // Bounded opportunistic cleanup. Lock before deleting, so active requests cannot lose a room.
    if(random_int(1,30)===1){$n=0;foreach(new DirectoryIterator($dir) as $entry){if(++$n>200)break;if(!$entry->isFile()||$entry->getMTime()>$now-86400)continue;$old=@fopen($entry->getPathname(),'r+');if($old){if(flock($old,LOCK_EX|LOCK_NB)){if(filemtime($entry->getPathname())<$now-86400)@unlink($entry->getPathname());flock($old,LOCK_UN);}fclose($old);}}}
    $hosting=$action==='online_host';$code=strtoupper(trim((string)($in['code']??'')));$fp=false;
    if($hosting){
        if(!in_array($in['gameType']??'', ['regular','ultimate'],true))throw new RuntimeException('Choose a valid game type.');
        for($i=0;$i<8;$i++){$code='';for($j=0;$j<8;$j++)$code.='ABCDEFGHJKLMNPQRSTUVWXYZ23456789'[random_int(0,31)];$fp=@fopen($dir.'/'.$code.'.json','x+');if($fp)break;}
        if(!$fp)throw new RuntimeException('Could not create a room. Try again.');
    }else{
        if(!preg_match('/^[A-HJ-NP-Z2-9]{8}$/D',$code))throw new RuntimeException('Enter the eight-character room code.');
        $fp=@fopen($dir.'/'.$code.'.json','r+');if(!$fp)throw new RuntimeException('Room not found or expired.');
    }
    try{
        if(!flock($fp,LOCK_EX))throw new RuntimeException('Room is busy. Try again.');
        $room=$hosting?['code'=>$code,'gameType'=>$in['gameType'],'players'=>['X'=>$player,'O'=>null],'seen'=>['X'=>$now,'O'=>0],'moves'=>[],'revision'=>0,'round'=>1,'rematch'=>[],'closed'=>false,'updated'=>$now]:json_decode(stream_get_contents($fp),true);
        if(!is_array($room)||$room['updated']<$now-86400)throw new RuntimeException('This room has expired. Host a new game.');
        $mark=array_search($player,$room['players'],true);
        if($action==='online_join'&&$mark===false){if($room['closed']||$room['players']['O']!==null)throw new RuntimeException('This room is full or closed.');$room['players']['O']=$player;$mark='O';$room['revision']++;}
        if($mark===false)throw new RuntimeException('Join this room before playing.');
        $s=onlineReplay($room['moves'],$room['gameType']);
        if($action==='online_move'){
            if($room['closed']||!$room['players']['O'])throw new RuntimeException('Waiting for an opponent, or the room has closed.');
            if(($in['revision']??null)!==$room['revision'])throw new RuntimeException('The board changed. Your game will synchronize automatically.');
            if($s['turn']!==$mark)throw new RuntimeException('It is not your turn.');
            $moves=$room['moves'];$moves[]=$in['move']??null;onlineReplay($moves,$room['gameType']);$room['moves']=$moves;$room['revision']++;
        }elseif($action==='online_rematch'){
            if(!$s['result']||$room['closed'])throw new RuntimeException('Finish this game before requesting a rematch.');
            if(($in['round']??null)!==$room['round'])throw new RuntimeException('A new round has already started.');
            $room['rematch'][$mark]=true;
            if(count($room['rematch'])===2){$room['moves']=[];$room['rematch']=[];$room['round']++;}$room['revision']++;
        }elseif($action==='online_leave'){$room['closed']=true;$room['revision']++;}
        elseif(!in_array($action,['online_host','online_join','online_poll'],true))throw new RuntimeException('Unknown online action.');
        $room['seen'][$mark]=$now;$room['updated']=$now;
        $json=json_encode($room,JSON_THROW_ON_ERROR);rewind($fp);if(fwrite($fp,$json)!==strlen($json)||!ftruncate($fp,strlen($json))||!fflush($fp))throw new RuntimeException('Could not save the room.');
        $_SESSION['uttt_online']=$action==='online_leave'?null:$code;
        return ['code'=>$code,'gameType'=>$room['gameType'],'mark'=>$mark,'joined'=>$room['players']['O']!==null,'opponentOnline'=>$room['seen'][$mark==='X'?'O':'X']>$now-15,'moves'=>$room['moves'],'revision'=>$room['revision'],'round'=>$room['round'],'rematch'=>$room['rematch'],'closed'=>$room['closed']];
    }finally{flock($fp,LOCK_UN);fclose($fp);}
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input) || !hash_equals($_SESSION['uttt_csrf'], (string)($input['token'] ?? ''))) {
        http_response_code(403); echo json_encode(['error'=>'Session expired. Reload the page.']); exit;
    }
    if(str_starts_with((string)($input['action']??''),'online_')){
        try{echo json_encode(onlineRequest($input));}catch(Throwable $e){http_response_code(400);echo json_encode(['error'=>$e instanceof RuntimeException?$e->getMessage():'Online play is unavailable. Please try again.']);}exit;
    }
    // Store a bounded move log rather than trusting a client-supplied board.
    $moves = $input['moves'] ?? null;
    if (!is_array($moves) || count($moves)>81 || !in_array($input['mode'] ?? '', ['computer','local'],true) || !in_array($input['difficulty'] ?? '', ['easy','normal','hard'],true)) {
        http_response_code(400); echo json_encode(['error'=>'Invalid game.']); exit;
    }
    $gameType=$input['gameType'] ?? 'ultimate';
    if(!in_array($gameType,['ultimate','regular'],true)){http_response_code(400);echo json_encode(['error'=>'Invalid game type.']);exit;}
    $boards=array_fill(0,9,array_fill(0,9,'')); $won=array_fill(0,9,''); $target=-1; $turn='X'; $result='';
    $lines=[[0,1,2],[3,4,5],[6,7,8],[0,3,6],[1,4,7],[2,5,8],[0,4,8],[2,4,6]];
    $winner = static function(array $cells) use ($lines): string {
        foreach($lines as [$a,$b,$c]) if(in_array($cells[$a],['X','O'],true) && $cells[$a]===$cells[$b] && $cells[$a]===$cells[$c]) return $cells[$a];
        return in_array('',$cells,true) ? '' : 'D';
    };
    foreach($moves as $move) {
        if(!is_array($move) || count($move)!==2 || !isset($move[0],$move[1]) || !is_int($move[0]) || !is_int($move[1])) { $result='invalid'; break; }
        [$b,$c]=$move;
        if($result!=='' || $b<0 || $b>8 || $c<0 || $c>8 || $won[$b]!=='' || $boards[$b][$c]!=='' || ($target!==-1 && $target!==$b) || ($gameType==='regular' && $b!==0)) { $result='invalid'; break; }
        $boards[$b][$c]=$turn; $won[$b]=$winner($boards[$b]); $result=$gameType==='regular'?$won[0]:$winner($won); $target=$gameType==='regular'?0:($won[$c]==='' ? $c : -1); $turn=$turn==='X'?'O':'X';
    }
    if($result==='invalid') { http_response_code(400); echo json_encode(['error'=>'Invalid move sequence.']); exit; }
    $_SESSION['uttt_game']=['gameType'=>$gameType,'moves'=>$moves,'mode'=>$input['mode'],'difficulty'=>$input['difficulty'],'human'=>($input['human'] ?? 'X')==='O'?'O':'X'];
    echo json_encode(['ok'=>true]); exit;
}
$initial=$_SESSION['uttt_game'] ?? ['moves'=>[],'mode'=>'computer','difficulty'=>'normal','human'=>'X'];
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Ultimate Tic Tac Toe</title>
<style>
:root{color-scheme:dark;--bg:#0b1120;--panel:#141e32;--cell:#1c2a43;--text:#edf3ff;--muted:#9eacc3;--line:#34445f;--accent:#a4edb3;--x:#79bdff;--o:#ffad8a;--shadow:0 18px 60px #0004}*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font-family:system-ui,sans-serif}body.light{color-scheme:light;--bg:#edf2f8;--panel:#fff;--cell:#edf2f8;--text:#17243a;--muted:#596980;--line:#c5d0df;--accent:#227e40;--x:#186db2;--o:#b54a23;--shadow:0 18px 60px #26395415}main{max-width:1080px;margin:auto;padding:26px 20px}header{display:flex;justify-content:space-between;align-items:center;gap:15px;margin-bottom:22px}h1{font-size:clamp(23px,4vw,35px);margin:4px 0}p{color:var(--muted);line-height:1.55}.eyebrow{color:var(--accent);font-size:11px;letter-spacing:2px;font-weight:800}.layout{display:grid;grid-template-columns:minmax(0,650px) 280px;gap:24px}.panel{background:var(--panel);border:1px solid var(--line);border-radius:18px;padding:20px;box-shadow:var(--shadow)}.board{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;padding:3px}.mini{position:relative;display:grid;grid-template-columns:repeat(3,1fr);gap:4px;border:2px solid transparent;border-radius:10px;padding:6px;background:var(--bg);transition:border-color .15s}.mini.active{border-color:var(--accent)}.mini.closed .cell{opacity:.18}.cell{border:0;border-radius:5px;background:var(--cell);color:var(--text);aspect-ratio:1;font-size:clamp(17px,4vw,38px);font-weight:800;padding:0;cursor:pointer;min-width:0}.cell:disabled{cursor:default}.cell:not(:disabled):hover{background:var(--line)}.cell:focus-visible,button:focus-visible,select:focus-visible{outline:3px solid var(--accent);outline-offset:2px}.cell.x,.claim.x{color:var(--x)}.cell.o,.claim.o{color:var(--o)}.cell.last{box-shadow:inset 0 0 0 2px var(--muted)}.claim{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;font-size:clamp(45px,10vw,95px);font-weight:850;pointer-events:none}.claim.draw{font-size:15px;color:var(--muted)}.status{font-size:21px;font-weight:750;margin:0 0 5px}.hint{font-size:14px;margin:0 0 18px;min-height:22px}label{display:block;color:var(--muted);font-size:13px;margin:15px 0 6px}select,.btn{font:inherit;color:var(--text);background:var(--cell);border:1px solid var(--line);border-radius:8px;padding:10px 12px}select{width:100%}.btn{cursor:pointer}.primary{background:var(--accent);color:var(--bg);font-weight:750;border-color:var(--accent);width:100%;margin-top:18px}.controls{display:flex;gap:8px;margin-top:10px}.controls .btn{flex:1}.score{display:flex;justify-content:space-between;border-bottom:1px solid var(--line);padding:13px 0}.score strong{font-size:24px}details{margin-top:20px;color:var(--muted);font-size:14px}summary{cursor:pointer;color:var(--text);font-weight:650}li{margin:9px 0;line-height:1.5}footer{margin-top:15px;color:var(--muted);font-size:12px}.error{color:var(--o)}@media(max-width:780px){.layout{grid-template-columns:1fr}.panel{padding:14px}.board{gap:6px}.mini{padding:4px;gap:3px}main{padding:16px 12px}.side{display:grid;grid-template-columns:1fr 1fr;gap:0 20px}.side .settings{grid-row:span 2}details{grid-column:1/-1}}@media(prefers-reduced-motion:reduce){*{transition:none!important}}

.result-banner{margin:0 0 18px;padding:18px;border-radius:12px;border:2px solid var(--accent);background:var(--bg);text-align:center}.result-banner[hidden]{display:none}.result-banner.loss{border-color:var(--o)}.result-banner.draw{border-color:var(--line)}.result-icon{font-size:38px}.result-title{font-size:24px;font-weight:800;margin:4px 0}.result-detail{margin:0;font-size:14px}.mini.winning{border-color:var(--accent);box-shadow:0 0 0 2px var(--accent)}.confetti{position:fixed;inset:0;overflow:hidden;pointer-events:none;z-index:50}.confetti i{position:absolute;top:-24px;width:9px;height:15px;animation:confetti-fall 2.8s ease-in forwards}@keyframes confetti-fall{to{transform:translate(var(--drift),110vh) rotate(var(--spin));opacity:0}}@media(prefers-reduced-motion:reduce){.confetti{display:none}}
body.light .mini{background:#8b9bb1}body.light .cell{background:#fff}body.light .cell:not(:disabled):hover{background:#e2eaf4}
body.light .mini.active{background:#227e40;border-color:#145c2c;box-shadow:0 0 0 3px #227e40}body.light .mini.active .cell{background:#edf9ef}body.light .mini.active .cell:not(:disabled):hover{background:#d1efd9}
dialog{background:var(--panel);color:var(--text);border:1px solid var(--line);border-radius:16px;padding:22px;max-width:min(520px,92vw);max-height:85dvh;overflow:auto}dialog::backdrop{background:#0009}.confetti{z-index:9999}main{padding:12px 20px}header{margin-bottom:12px}.panel{padding:14px}.hint{margin-bottom:8px}.side label{margin-top:9px}.side select,.side .btn{padding:7px 10px}.side .score{padding:7px 0}.side p{margin:10px 0;font-size:12px}.primary{margin-top:12px}.board{width:min(100%,calc(100dvh - 185px));margin:auto}.board.regular{grid-template-columns:1fr;max-width:490px}.board.regular .mini{padding:8px;gap:8px}.board.regular .cell{font-size:clamp(36px,8vh,80px)}.board.regular .claim{display:none}footer{margin-top:8px}.status{font-size:18px}#help{margin-top:12px}@media(min-width:781px){.layout{grid-template-columns:minmax(0,650px) 280px;gap:16px}.side{max-height:calc(100dvh - 105px);overflow:auto}}@media(max-width:780px){.board{width:min(100%,60dvh)}.side{gap:0 12px}}
input{font:inherit;padding:10px;border-radius:8px;border:1px solid var(--line);background:var(--cell);color:var(--text);max-width:100%}#onlineSetup,#roomPanel{margin-top:12px}#roomCode{letter-spacing:2px}#onlineError:empty{display:none}[hidden]{display:none!important}
</style></head><body><main><header><div><div class="eyebrow">NINE BOARDS. ONE WINNER.</div><h1>Ultimate Tic Tac Toe</h1></div><button class="btn" id="theme" aria-label="Toggle light or dark theme">☀ Light</button></header><div class="layout"><section class="panel"><div class="status" id="status" role="status" aria-live="polite"></div><p class="hint" id="hint"></p><div class="board" id="board" aria-label="Ultimate tic tac toe board"></div><footer id="save">Games save automatically in this browser session.</footer></section><aside class="panel side"><div class="settings"><div class="eyebrow">GAME SETUP</div><label for="gameType">Game type</label><select id="gameType"><option value="ultimate">Ultimate Tic Tac Toe</option><option value="regular">Regular Tic Tac Toe</option></select><label for="mode">Play mode</label><select id="mode"><option value="computer">You vs computer</option><option value="local">Two players · same screen</option><option value="online">Online play</option></select><div id="onlineSetup" hidden><button class="btn" id="host">Host a game</button> <button class="btn" id="join">Join a game</button></div><div id="roomPanel" hidden><p>Room <strong id="roomCode"></strong> · You are <strong id="roomMark"></strong></p><p id="roomStatus" role="status"></p><button class="btn" id="copyCode">Copy code</button> <button class="btn" id="leave">Leave</button><button class="btn" id="onlineRematch" hidden>Request rematch</button></div><p id="onlineError" class="error" role="alert"></p><label for="difficulty">Computer difficulty</label><select id="difficulty"><option value="easy">Easy</option><option value="normal">Normal</option><option value="hard">Hard</option></select><label for="human">Your mark</label><select id="human"><option value="X">X · play first</option><option value="O">O · computer starts</option></select><button id="new" class="btn primary">New game</button><div class="controls"><button class="btn" id="undo">Undo turn</button></div><p id="setupNote" style="font-size:12px">Setup changes apply to your next game.</p></div><div><div class="score"><span style="color:var(--x)">X boards</span><strong id="xs">0</strong></div><div class="score"><span style="color:var(--o)">O boards</span><strong id="os">0</strong></div><div class="score"><span>Draw boards</span><strong id="ds">0</strong></div><p id="moveCount">Move 1</p></div><button class="btn" id="help">How to play</button></aside></div><footer>v1.3.0 · PHP</footer></main>
<dialog id="joinDialog" aria-labelledby="joinTitle"><h2 id="joinTitle">Join an online game</h2><form id="joinForm"><label for="joinCode">Room code</label><input id="joinCode" maxlength="8" autocomplete="off" autocapitalize="characters" required pattern="[A-Za-z2-9]{8}"><p id="joinError" class="error" role="alert"></p><button class="btn" type="submit">Join game</button> <button class="btn" type="button" id="joinCancel">Cancel</button></form></dialog><dialog id="resultDialog" aria-labelledby="resultTitle"><div id="resultBanner" class="result-banner" hidden><div id="resultIcon" class="result-icon" aria-hidden="true"></div><div id="resultTitle" class="result-title"></div><p id="resultDetail" class="result-detail"></p></div><button class="btn" id="resultClose">View board</button> <button class="btn" id="rematch">New game</button></dialog><dialog id="helpDialog"><h2>How to play</h2><p>Regular: make three marks in a row on one board.</p><p>Ultimate:</p><ol><li>X starts and can choose any square.</li><li>Your square sends the next player to the matching small board. Top-right square → top-right board.</li><li>Win a small board with three in a row. Won and drawn boards close.</li><li>If sent to a closed board, choose any open board.</li><li>Win three small boards in a row to win the game. If all boards close without a winner, it is a draw.</li></ol><button class="btn" id="helpClose">Close</button></dialog><script>
'use strict';
let INITIAL=<?=json_encode($initial, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;
try{const cached=JSON.parse(localStorage.getItem('uttt-progress:'+location.pathname));if(cached&&Array.isArray(cached.moves)&&['computer','local'].includes(cached.mode)&&['easy','normal','hard'].includes(cached.difficulty)&&['X','O'].includes(cached.human)&&['ultimate','regular'].includes(cached.gameType))INITIAL=cached;}catch{}
const TOKEN=<?=json_encode($_SESSION['uttt_csrf'])?>;
const LINES=[[0,1,2],[3,4,5],[6,7,8],[0,3,6],[1,4,7],[2,5,8],[0,4,8],[2,4,6]];
const other=p=>p==='X'?'O':'X';
function winner(c){for(const [a,b,d] of LINES)if((c[a]==='X'||c[a]==='O')&&c[a]===c[b]&&c[a]===c[d])return c[a];return c.includes('')?'':'D';}
function fresh(gameType="ultimate"){return {gameType,boards:Array.from({length:9},()=>Array(9).fill('')),won:Array(9).fill(''),target:-1,turn:'X',result:'',moves:[]};}
function legal(s){if(s.result)return [];const out=[];for(let b=0;b<(s.gameType==='regular'?1:9);b++)if(!s.won[b]&&(s.target===-1||s.target===b))for(let c=0;c<9;c++)if(!s.boards[b][c])out.push([b,c]);return out;}
function apply(s,b,c){if(!legal(s).some(m=>m[0]===b&&m[1]===c))throw Error('Illegal move');const t={...s,boards:s.boards.map(a=>a.slice()),won:s.won.slice(),moves:[...s.moves,[b,c]]};t.boards[b][c]=s.turn;t.won[b]=winner(t.boards[b]);t.result=s.gameType==='regular'?t.won[0]:winner(t.won);t.target=s.gameType==='regular'?0:t.won[c]?-1:c;t.turn=other(s.turn);return t;}
function replay(moves,gameType=INITIAL.gameType||"ultimate"){let s=fresh(gameType);for(const [b,c] of moves)s=apply(s,b,c);return s;}
function evaluate(s,p){if(s.result)return s.result===p?100000:s.result===other(p)?-100000:0;let v=0;for(let b=0;b<9;b++){const weight=b===4?1.3:[0,2,6,8].includes(b)?1.15:1;if(s.won[b]){v+=s.won[b]===p?160*weight:s.won[b]===other(p)?-160*weight:0;continue;}for(const line of LINES){const a=line.map(i=>s.boards[b][i]);if(!a.includes(other(p)))v+=([0,2,15,0][a.filter(x=>x===p).length])*weight;if(!a.includes(p))v-=([0,2,17,0][a.filter(x=>x===other(p)).length])*weight;}}for(const line of LINES){const a=line.map(i=>s.won[i]);if(a.every(x=>x===''||x===p))v+=[0,30,340,0][a.filter(x=>x===p).length];if(a.every(x=>x===''||x===other(p)))v-=[0,30,360,0][a.filter(x=>x===other(p)).length];}return v;}
function choose(s,difficulty){const moves=legal(s);if(difficulty==='easy')return moves[Math.floor(Math.random()*moves.length)];const p=s.turn;let budget= difficulty==='hard'?5500:900;
function search(t,depth,alpha,beta){if(t.result||depth===0||--budget<=0)return evaluate(t,p);const max=t.turn===p;let best=max?-Infinity:Infinity;let children=legal(t).map(([b,c])=>({m:[b,c],t:apply(t,b,c)}));children.sort((a,b)=>max?evaluate(b.t,p)-evaluate(a.t,p):evaluate(a.t,p)-evaluate(b.t,p));children=children.slice(0,depth>1?14:81);for(const a of children){const val=search(a.t,depth-1,alpha,beta);best=max?Math.max(best,val):Math.min(best,val);if(max)alpha=Math.max(alpha,best);else beta=Math.min(beta,best);if(beta<=alpha)break;}return best;}
const ranked=moves.map(([b,c])=>({m:[b,c],t:apply(s,b,c)})).sort((a,b)=>evaluate(b.t,p)-evaluate(a.t,p));let best=-Infinity,options=[];for(const a of ranked){const value=search(a.t,difficulty==='hard'?3:1,-Infinity,Infinity);if(value>best){best=value;options=[a.m];}else if(value===best)options.push(a.m);}return options[Math.floor(Math.random()*options.length)];}
let state;try{state=replay(INITIAL.moves);}catch{INITIAL={moves:[],gameType:'ultimate',mode:'computer',difficulty:'normal',human:'X'};state=fresh();}
let config={gameType:INITIAL.gameType||"ultimate",mode:INITIAL.mode,difficulty:INITIAL.difficulty,human:INITIAL.human},thinking=false,timer=null,epoch=0;
const $=id=>document.getElementById(id);
for(const k of ['gameType','mode','difficulty','human'])$(k).value=config[k];
let room=null,onlineBusy=false,pollTimer=null,onlineGeneration=0;
let saveQueue=Promise.resolve();
function save(){if(room)return;try{localStorage.setItem('uttt-progress:'+location.pathname,JSON.stringify({...config,moves:state.moves}));}catch{}const payload=JSON.stringify({...config,moves:state.moves,token:TOKEN});saveQueue=saveQueue.catch(()=>{}).then(async()=>{try{const r=await fetch(location.pathname+location.search,{method:'POST',headers:{'Content-Type':'application/json'},body:payload});if(!r.ok)throw Error('Save failed');$('save').textContent='Progress saved automatically.';$('save').className='';}catch(e){$('save').textContent='Server save unavailable. Browser recovery is active when browser storage is available.';$('save').className='error';}});}
function outcome(result,mode,human){
 if(result==='D')return {kind:'draw',icon:'🤝',title:'It’s a draw!',detail:'Nobody takes the crown this round. Ready for a rematch?'};
 if(mode==='online')return result===human?{kind:'win',icon:'🏆',title:'You win!',detail:'Ready for another round?'}:{kind:'loss',icon:'👏',title:'Your opponent wins.',detail:'Request a rematch to play again.'};
 if(mode==='computer'&&result!==human)return {kind:'loss',icon:'🤖',title:'Computer wins. You lost this round.',detail:'The computer takes the crown. Hit New game and take it back!'};
 return {kind:'win',icon:'🏆',title:mode==='computer'?'You win!':`${result} wins the game!`,detail:mode==='computer'?'You beat the computer. Victory is yours!':'Three in a row. A well-earned victory!'};
}
let celebrated=null,confettiTimer=null;
function clearCelebration(){clearTimeout(confettiTimer);document.querySelector('.confetti')?.remove();}
function celebrate(){
 clearCelebration();if(window.matchMedia('(prefers-reduced-motion: reduce)').matches)return;
 const layer=document.createElement('div');layer.className='confetti';layer.setAttribute('aria-hidden','true');
 const colors=['#79bdff','#ffad8a','#a4edb3','#ffd166','#c4a6ff'];
 for(let i=0;i<65;i++){const piece=document.createElement('i');piece.style.left=`${Math.random()*100}%`;piece.style.background=colors[i%colors.length];piece.style.animationDelay=`${Math.random()*.6}s`;piece.style.setProperty('--drift',`${Math.random()*240-120}px`);piece.style.setProperty('--spin',`${Math.random()*1080-540}deg`);layer.append(piece);}
 document.body.append(layer);confettiTimer=setTimeout(clearCelebration,3600);
}
function renderOutcome(){
 const banner=$('resultBanner');banner.hidden=!state.result;
 if(!state.result){celebrated=null;clearCelebration();$('resultDialog').close();return;}
 const o=outcome(state.result,config.mode,config.human);banner.className='result-banner '+o.kind;$('resultIcon').textContent=o.icon;$('resultTitle').textContent=o.title;$('resultDetail').textContent=o.detail;
 const key=JSON.stringify([room?.round,state.moves]);if(celebrated!==key){celebrated=key;if(!$('resultDialog').open)$('resultDialog').showModal();if(o.kind==='win')celebrate();}
 if(state.gameType!=='regular'&&state.result!=='D')for(const line of LINES)if(line.every(b=>state.won[b]===state.result))for(const b of line)$('board').children[b].classList.add('winning');
}
function render(){const bot=(config.mode==='computer'&&state.turn!==config.human)||!!(room&&(!room.joined||room.closed||onlineBusy||state.turn!==room.mark));const names=['top-left','top-middle','top-right','middle-left','center','middle-right','bottom-left','bottom-middle','bottom-right'];$('status').textContent=state.result?(outcome(state.result,config.mode,config.human).title):thinking?'Computer is thinking…':`${state.turn} to play${bot?' · computer':config.mode==='computer'?' · your turn':''}`;$('hint').textContent=state.result?'Start a new game for a rematch.':state.target===-1?'Choose any highlighted board.':`Play in the ${names[state.target]} board.`;const root=$('board');root.classList.toggle('regular',state.gameType==='regular');$('board').setAttribute('aria-label',state.gameType+' tic tac toe board');document.querySelector('h1').textContent=state.gameType==='regular'?'Tic Tac Toe':'Ultimate Tic Tac Toe';document.querySelector('header .eyebrow').textContent=state.gameType==='regular'?'ONE BOARD. THREE IN A ROW.':'NINE BOARDS. ONE WINNER.';if(!state.result&&state.gameType==='regular')$('hint').textContent='Choose any empty square.';root.replaceChildren();const last=state.moves.at(-1);for(let b=0;b<(state.gameType==='regular'?1:9);b++){const mini=document.createElement('div');mini.className='mini'+(state.won[b]?' closed':!state.result&&(state.target===-1||state.target===b)?' active':'');mini.setAttribute('role','group');mini.setAttribute('aria-label',`${names[b]} board${state.won[b]?' · '+(state.won[b]==='D'?'draw':state.won[b]+' won'):''}`);for(let c=0;c<9;c++){const mark=state.boards[b][c],cell=document.createElement('button');cell.className='cell '+mark.toLowerCase()+(last&&last[0]===b&&last[1]===c?' last':'');cell.textContent=mark;cell.disabled=!!(thinking||bot||state.result||state.won[b]||mark||(state.target!==-1&&state.target!==b));cell.setAttribute('aria-label',`${names[b]} board, ${names[c]} square${mark?', '+mark:''}`);cell.onclick=()=>{if(onlineBusy)return;if(room){sendMove(b,c);return;}state=apply(state,b,c);save();render();schedule();};mini.append(cell);}if(state.won[b]){const claim=document.createElement('div');claim.className='claim '+(state.won[b]==='D'?'draw':state.won[b].toLowerCase());claim.textContent=state.won[b]==='D'?'DRAW':state.won[b];mini.append(claim);}root.append(mini);}$('xs').textContent=state.won.filter(x=>x==='X').length;$('os').textContent=state.won.filter(x=>x==='O').length;$('ds').textContent=state.won.filter(x=>x==='D').length;$('moveCount').textContent=`${state.moves.length} moves played`;$('undo').disabled=!state.moves.length||(config.mode==='computer'&&config.human==='O'&&state.moves.length===1);renderOutcome();renderOnline();}
function schedule(){if(onlineBusy||room||state.result||config.mode!=='computer'||state.turn===config.human)return;thinking=true;render();const generation=epoch;timer=setTimeout(()=>{if(generation!==epoch)return;const m=choose(state,config.difficulty);state=apply(state,...m);thinking=false;save();render();},300);}
$('new').onclick=()=>{if(room){requestRematch();return;}if($('mode').value==='online'){updateSetup();$('onlineError').textContent='Choose Host a game or Join a game.';return;}if(state.moves.length&&!state.result&&!confirm('End this game and start a new one?'))return;epoch++;clearTimeout(timer);thinking=false;config={gameType:$('gameType').value,mode:$('mode').value,difficulty:$('difficulty').value,human:$('human').value};state=fresh(config.gameType);save();render();schedule();};
$('undo').onclick=()=>{if(room)return;epoch++;clearTimeout(timer);thinking=false;let moves=state.moves.slice(0,-1);if(config.mode==='computer'){let s=replay(moves,config.gameType);while(moves.length&&s.turn!==config.human){moves.pop();s=replay(moves,config.gameType);}}state=replay(moves,config.gameType);save();render();schedule();};
function theme(light){document.body.classList.toggle('light',light);$('theme').textContent=light?'☾ Dark':'☀ Light';try{localStorage.setItem('uttt-theme',light?'light':'dark');}catch{}}
let light=false;try{light=localStorage.getItem('uttt-theme')==='light';}catch{}theme(light);$('theme').onclick=()=>theme(!document.body.classList.contains('light'));
$('help').onclick=()=>$('helpDialog').showModal();$('helpClose').onclick=()=>$('helpDialog').close();$('resultClose').onclick=()=>$('resultDialog').close();$('rematch').onclick=()=>{$('resultDialog').close();$('new').click();};
async function onlineApi(action,extra={}){
 const response=await fetch(location.pathname+location.search,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action,token:TOKEN,...extra})});
 const data=await response.json();if(!response.ok||data.error)throw Error(data.error||'Online request failed.');return data;
}
function updateSetup(){
 const online=$('mode').value==='online';$('onlineSetup').hidden=!online||!!room;
 for(const id of ['difficulty','human']){$(id).disabled=online||!!room;document.querySelector(`label[for="${id}"]`).hidden=online;$(id).hidden=online;}
 $('mode').disabled=!!room;$('gameType').disabled=!!room;$('new').hidden=online||!!room;
}
function renderOnline(){
 $('roomPanel').hidden=!room;$('undo').hidden=!!room;$('setupNote').hidden=!!room;if(!room){updateSetup();return;}
 $('roomCode').textContent=room.code;$('roomMark').textContent=room.mark;
 $('roomStatus').textContent=room.closed?'Room closed. Leave to play another game.':!room.joined?'Waiting for your opponent to enter the code.':room.opponentOnline?'Opponent connected.':'Opponent disconnected or away. Waiting for them to return.';
 if(!state.result){$('status').textContent=room.closed?'Room closed':!room.joined?'Waiting for opponent…':state.turn===room.mark?'Your turn · '+room.mark:'Opponent’s turn · '+state.turn;}
 $('save').textContent='Online game synchronized automatically.';$('undo').disabled=true;
 $('onlineRematch').hidden=!state.result||room.closed;
 $('onlineRematch').disabled=onlineBusy||!!room.rematch[room.mark];
 $('onlineRematch').textContent=room.rematch[room.mark]?'Waiting for rematch approval…':Object.keys(room.rematch).length?'Accept rematch':'Request rematch';
 $('rematch').hidden=!!room;$('new').hidden=true;updateSetup();
}
function acceptRoom(data){
 if(room&&room.code===data.code&&data.revision<room.revision)return;
 epoch++;clearTimeout(timer);thinking=false;room=data;config={gameType:data.gameType,mode:'online',difficulty:'normal',human:data.mark};state=replay(data.moves,data.gameType);
 for(const k of ['gameType','mode','human'])$(k).value=config[k];
 render();
}
function queuePoll(){clearTimeout(pollTimer);if(room)pollTimer=setTimeout(pollRoom,1200);}
async function pollRoom(){
 if(!room)return;if(onlineBusy){queuePoll();return;}
 const generation=onlineGeneration,code=room.code;
 try{const data=await onlineApi('online_poll',{code});if(generation!==onlineGeneration||!room||room.code!==code)return;acceptRoom(data);$('onlineError').textContent='';}
 catch(e){if(generation===onlineGeneration&&room){$('onlineError').textContent=e.message+' Retrying…';$('save').textContent='Connection interrupted. Reconnecting…';}}
 finally{if(generation===onlineGeneration)queuePoll();}
}
async function enterRoom(action,extra={}){
 if(onlineBusy||room)return;
 if(state.moves.length&&!state.result&&!confirm('Pause your current game and enter online play?'))return;
 onlineBusy=true;epoch++;clearTimeout(timer);thinking=false;const generation=++onlineGeneration;$('host').disabled=$('join').disabled=true;$('copyCode').textContent='Copy code';
 try{const data=await onlineApi(action,extra);if(generation!==onlineGeneration)return;acceptRoom(data);$('joinDialog').close();$('onlineError').textContent='';queuePoll();}
 catch(e){$('onlineError').textContent=e.message;$('joinError').textContent=e.message;}
 finally{onlineBusy=false;$('host').disabled=$('join').disabled=false;render();if(!room)schedule();}
}
async function sendMove(b,c){
 if(!room||onlineBusy||room.closed||!room.joined||state.turn!==room.mark)return;
 onlineBusy=true;render();const generation=onlineGeneration,code=room.code;
 try{const data=await onlineApi('online_move',{code,move:[b,c],revision:room.revision});if(generation===onlineGeneration)acceptRoom(data);$('onlineError').textContent='';}
 catch(e){$('onlineError').textContent=e.message;}
 finally{onlineBusy=false;if(room){render();queuePoll();}}
}
async function requestRematch(){
 if(!room||onlineBusy||!state.result||room.closed)return;onlineBusy=true;
 try{acceptRoom(await onlineApi('online_rematch',{code:room.code,round:room.round}));$('onlineError').textContent='';}
 catch(e){$('onlineError').textContent=e.message;}
 finally{onlineBusy=false;if(room){render();queuePoll();}}
}
$('mode').onchange=updateSetup;
$('host').onclick=()=>enterRoom('online_host',{gameType:$('gameType').value});
$('join').onclick=()=>{$('joinError').textContent='';$('joinDialog').showModal();$('joinCode').focus();};
$('joinCancel').onclick=()=>$('joinDialog').close();
$('joinForm').onsubmit=e=>{e.preventDefault();enterRoom('online_join',{code:$('joinCode').value.trim().toUpperCase()});};
$('copyCode').onclick=async()=>{try{await navigator.clipboard.writeText(room.code);$('copyCode').textContent='Copied!';}catch{$('onlineError').textContent='Copy the room code shown above.';}};
$('onlineRematch').onclick=requestRematch;
$('leave').onclick=async()=>{
 if(!room||onlineBusy||!confirm('Leave this online game? The room will close for both players.'))return;
 onlineBusy=true;
 try{await onlineApi('online_leave',{code:room.code});onlineGeneration++;clearTimeout(pollTimer);room=null;epoch++;$('rematch').hidden=false;$('onlineError').textContent='';$('mode').value='computer';config={gameType:$('gameType').value,mode:'computer',difficulty:$('difficulty').value,human:'X'};state=fresh(config.gameType);save();render();updateSetup();}
 catch(e){$('onlineError').textContent=e.message;}
 finally{onlineBusy=false;}
};
async function resumeOnline(){
 const code=<?=json_encode($_SESSION['uttt_online']??null)?>;
 if(!code)return;onlineBusy=true;epoch++;clearTimeout(timer);thinking=false;
 // Disable board interaction until the saved online room has been resolved.
 $('board').inert=true;
 try{acceptRoom(await onlineApi('online_poll',{code}));queuePoll();}
 catch(e){$('onlineError').textContent=e.message;}
 finally{onlineBusy=false;$('board').inert=false;render();if(!room)schedule();}
}

render();updateSetup();resumeOnline();schedule();
</script></body></html>
