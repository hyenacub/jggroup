<?php
/* ============================================================
 *  유튜브 영상 목록 · 댓글 초안 · 댓글 달기
 *
 *  [영상 불러오기] → 다섯 채널의 최신 영상을 썸네일·제목·채널·올린 날짜로 보여 준다.
 *  목록에서 하나를 고르면 그 영상의 고민 한 줄과 댓글 초안(youtube_drafts.php)이 나온다.
 *  여기까지는 로그인 없이 쓴다. 우리 회원 로그인은 이 화면 어디에도 필요 없다.
 *
 *  [댓글 달기] 를 누르면 레이어가 뜨고, 거기서 사용자의 유튜브(Google) 계정으로 로그인해
 *  그 계정 이름으로 댓글을 올린다. 로그인은 Google Identity Services 의 토큰 방식이다.
 *    - Google 로그인 화면 자체는 레이어 안에 넣을 수 없다. Google 이 iframe 삽입을 막아 두었으므로
 *      레이어의 버튼이 Google 팝업을 띄우고, 돌아온 토큰으로 레이어가 이어서 진행한다.
 *    - 토큰은 브라우저 메모리에만 둔다(localStorage 도, 서버도 아님). 새로고침하면 다시 로그인한다.
 *      남의 계정에 글을 쓸 수 있는 값이라 오래 남겨 둘 이유가 없다.
 *    - 레이어의 입력칸에서 초안을 고치거나 지우고 새로 쓴 뒤 올린다. [게시] 를 누르면 글과 토큰이
 *      action=post 로 서버에 오고, 서버가 한도(영상당 1개·하루 10개)와 기준(yt_draft_problems())을
 *      본 뒤 대신 올린다(yt_post_guarded()). 브라우저가 유튜브를 직접 부르면 한도를 지킬 수 없다.
 *      장면 언급은 새로 쓴 글에는 보지 않는다.
 *
 *  상세는 서버가 영상마다 미리 그려 두고 JS 는 보이고 숨기기만 한다. JS 로도 그리게 하면
 *  초안 검사 결과를 그리는 곳이 두 군데가 된다. JS 가 없으면 ?v= 링크로 같은 화면이 나온다.
 * ============================================================ */
require_once __DIR__ . '/youtube.php';

/* ---------------- 게시 (레이어의 [게시] 가 부른다) ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'post') {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');

    // 토큰은 이 요청 안에서만 쓴다. 변수에서 밖으로(세션·파일·로그) 내보내지 않는다
    $token = (string)($_POST['token'] ?? '');
    $id    = (string)($_POST['v'] ?? '');
    $text  = mb_substr((string)($_POST['text'] ?? ''), 0, 1000, 'UTF-8');

    if ($token === '') {
        echo json_encode(['ok' => false, 'status' => 401, 'reason' => ''], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode(yt_post_guarded($id, $text, $token, yt_drafts()), JSON_UNESCAPED_UNICODE);
    exit;
}

$drafts   = yt_drafts();
$postLog  = yt_post_log();
$postLeft = max(0, YT_POST_PER_DAY - yt_posts_today($postLog, yt_today()));
$loaded   = isset($_GET['load']);
$selected = (string)($_GET['v'] ?? '');
$videos   = [];
$failed   = [];

if ($loaded) {
    // 버튼을 눌렀을 때만 새로 받는다(그래도 YT_FRESH_MIN 안쪽이면 캐시). 영상을 고르는 링크는 캐시를 쓴다
    // 초안이 없는 영상은 목록에서 뺀다(yt_latest_videos() 주석 참고)
    list($videos, $failed) = yt_latest_videos(isset($_GET['fresh']), $drafts);
}

$ids    = array_column($videos, 'id');
if (!in_array($selected, $ids, true)) {
    $selected = '';
}
$canPost = YT_OAUTH_CLIENT_ID !== '';

$postedVideos = [];   // 이미 댓글을 단 영상 — [댓글 달기] 대신 안내를 그린다
foreach ($postLog as $row) {
    $postedVideos[(string)($row['video'] ?? '')] = (string)($row['comment'] ?? '');
}

/** 피드 시각은 UTC 다. 채널들이 한국 기준으로 올리므로 KST 로 보여 준다. */
function yt_kst(int $ts): string
{
    $d = new DateTime('@' . $ts);
    $d->setTimezone(new DateTimeZone('Asia/Seoul'));

    return $d->format('Y-m-d H:i');
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>유튜브 댓글 초안</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/orioncactus/pretendard@v1.3.9/dist/web/variable/pretendardvariable-dynamic-subset.min.css">
<link rel="stylesheet" href="<?= yt_asset('assets/app.css') ?>">
<link rel="stylesheet" href="<?= yt_asset('assets/admin.css') ?>">
<?php if ($canPost): ?>
<script src="https://accounts.google.com/gsi/client" async></script>
<?php endif; ?>
</head>
<body class="admin">
<header class="admin-head">
  <div class="admin-head-inner">
    <h1>유튜브 댓글 초안</h1>
    <div class="admin-head-right">
      <span class="muted small yt-account" hidden></span>
      <button class="btn btn-text yt-signout" type="button" hidden>유튜브 로그아웃</button>
    </div>
  </div>
</header>

<main class="admin-main">
  <form class="yt-bar" method="get" action="youtube_comments.php">
    <input type="hidden" name="load" value="1">
    <input type="hidden" name="fresh" value="1">
    <button class="btn btn-primary" type="submit">영상 불러오기</button>
    <span class="muted small">
      <?= yt_h(implode(' · ', YT_CHANNELS)) ?> — 채널마다 최신 <?= YT_PER_CHANNEL ?>개
    </span>
    <?php if ($canPost): ?>
    <span class="muted small">· 오늘 남은 댓글 <strong class="yt-left"><?= $postLeft ?></strong>/<?= YT_POST_PER_DAY ?>개 (영상당 <?= YT_POST_PER_VIDEO ?>개)</span>
    <?php endif; ?>
  </form>

  <?php if ($failed !== []): ?>
  <p class="note note-warn">불러오지 못한 채널: <?= yt_h(implode(', ', $failed)) ?>. 잠시 뒤 다시 눌러 주세요.</p>
  <?php endif; ?>

  <?php if (!$loaded): ?>
  <p class="note note-info">[영상 불러오기]를 누르면 다섯 채널의 최신 영상이 나옵니다.</p>
  <?php elseif ($videos === []): ?>
  <p class="note note-danger">영상을 하나도 불러오지 못했습니다.</p>
  <?php else: ?>

  <div class="yt-layout">
    <ul class="yt-list" aria-label="영상 목록">
      <?php foreach ($videos as $v): ?>
      <li>
        <a class="yt-item<?= $v['id'] === $selected ? ' on' : '' ?>"
           href="youtube_comments.php?load=1&amp;v=<?= yt_h(rawurlencode($v['id'])) ?>#detail"
           data-video="<?= yt_h($v['id']) ?>"<?= $v['id'] === $selected ? ' aria-current="true"' : '' ?>>
          <img src="<?= yt_h($v['thumb']) ?>" alt="" loading="lazy" width="160" height="90">
          <span class="yt-meta">
            <strong><?= yt_h($v['title']) ?></strong>
            <span class="muted small"><?= yt_h($v['channel']) ?> · <?= yt_h(yt_kst($v['published'])) ?></span>
          </span>
        </a>
      </li>
      <?php endforeach; ?>
    </ul>

    <section class="yt-detail" id="detail" aria-live="polite">
      <p class="note note-info yt-pick"<?= $selected !== '' ? ' hidden' : '' ?>>목록에서 영상을 고르세요.</p>

      <?php foreach ($videos as $v):
          $d        = isset($drafts[$v['id']]) ? $drafts[$v['id']] : null;
          $problems = $d === null ? [] : yt_draft_problems($d);
      ?>
      <article class="panel yt-panel" data-video="<?= yt_h($v['id']) ?>" data-title="<?= yt_h($v['title']) ?>"<?= $v['id'] === $selected ? '' : ' hidden' ?>>
        <a href="<?= yt_h($v['url']) ?>" target="_blank" rel="noopener">
          <img class="yt-hero" src="<?= yt_h($v['thumb']) ?>" alt="" loading="lazy" width="480" height="270">
        </a>
        <h2><?= yt_h($v['title']) ?></h2>
        <p class="muted small"><?= yt_h($v['channel']) ?> · <?= yt_h(yt_kst($v['published'])) ?> ·
          <a href="<?= yt_h($v['url']) ?>" target="_blank" rel="noopener">유튜브에서 열기</a></p>

        <?php if ($d === null): ?>
        <p class="note note-warn">아직 초안이 없는 영상입니다. 자막을 확인한 뒤 <code>youtube_drafts.php</code> 에 추가해 주세요.</p>
        <?php else: ?>
        <div>
          <h3 class="yt-label">영상 속 고민</h3>
          <p class="yt-worry"><?= yt_h($d['worry']) ?></p>
        </div>
        <div>
          <h3 class="yt-label">댓글 초안</h3>
          <textarea class="yt-draft" rows="4" aria-label="댓글 초안"><?= yt_h($d['draft']) ?></textarea>
          <div class="yt-draft-foot">
            <span class="muted small"><span class="yt-count"><?= mb_strlen($d['draft'], 'UTF-8') ?></span>자
              (<?= YT_DRAFT_MIN ?>~<?= YT_DRAFT_MAX ?>자) · 언급한 장면: “<?= yt_h($d['cue']) ?>”</span>
            <span class="yt-actions">
              <button class="btn btn-secondary yt-copy" type="button">복사</button>
              <?php if ($canPost && !isset($postedVideos[$v['id']])): ?>
              <button class="btn btn-primary yt-post-open" type="button"<?= $postLeft === 0 ? ' disabled' : '' ?>>댓글 달기</button>
              <?php endif; ?>
            </span>
          </div>
        </div>
        <?php if ($problems === []): ?>
					<!--<p class="note note-info small">기준 통과 — 글자 수 · 링크 없음 · 해시태그 없음 · 이용 후기·결과 장담 없음 · 영상 속 장면 언급</p>-->
        <?php else: ?>
					<!--<p class="note note-danger small">기준 위반: <?= yt_h(implode(' / ', $problems)) ?></p>-->
        <?php endif; ?>
        <?php if ($canPost && isset($postedVideos[$v['id']])): ?>
        <p class="note note-info small">이 영상에는 이미 댓글을 달았습니다(영상당 <?= YT_POST_PER_VIDEO ?>개).
          <a href="<?= yt_h($v['url'] . '&lc=' . rawurlencode($postedVideos[$v['id']])) ?>" target="_blank" rel="noopener">단 댓글 보기</a></p>
        <?php elseif ($canPost && $postLeft === 0): ?>
        <p class="note note-warn small">오늘은 댓글을 <?= YT_POST_PER_DAY ?>개 모두 달았습니다. 내일 다시 달 수 있습니다.</p>
        <?php endif; ?>
        <?php if (!$canPost): ?>
        <p class="muted small">댓글 달기는 꺼져 있습니다(.env 에 YOUTUBE_OAUTH_CLIENT_ID 가 없음). [복사]로 가져가 유튜브에서 직접 달아 주세요.</p>
        <?php endif; ?>
        <?php endif; ?>
      </article>
      <?php endforeach; ?>
    </section>
  </div>
  <?php endif; ?>
</main>

<?php if ($canPost): ?>
<dialog class="yt-layer" aria-labelledby="yt-layer-title">
  <div class="yt-layer-head">
    <h2 id="yt-layer-title">댓글 달기</h2>
    <button class="btn btn-text yt-layer-close" type="button" aria-label="닫기">닫기</button>
  </div>
  <p class="muted small yt-layer-video"></p>

  <!-- 1) 로그인 전 -->
  <div class="yt-step" data-step="login">
    <p>댓글은 <strong>여러분의 유튜브 계정 이름</strong>으로 올라갑니다. 먼저 유튜브(Google) 계정으로 로그인해 주세요.</p>
    <button class="btn btn-primary btn-block yt-signin" type="button">유튜브 계정으로 로그인</button>
    <p class="muted small">Google 로그인 창이 따로 열립니다. 이 사이트는 비밀번호를 받지 않으며, 로그인 정보는 이 창을 닫거나 새로고침하면 사라집니다.</p>
  </div>

  <!-- 2) 고쳐 쓰고 게시 -->
  <div class="yt-step" data-step="confirm" hidden>
    <p class="small"><span class="yt-layer-account"></span> 채널 이름으로 올립니다. 초안을 고치거나 지우고 새로 써도 됩니다.</p>    <textarea class="yt-draft yt-layer-text" rows="5" aria-label="올릴 댓글"></textarea>
    <div class="yt-draft-foot">
      <span class="muted small"><span class="yt-layer-count">0</span>자 (<?= YT_DRAFT_MIN ?>~<?= YT_DRAFT_MAX ?>자) · 링크·해시태그 불가</span>
      <span class="yt-actions">
        <button class="btn btn-text yt-layer-reset" type="button">초안으로 되돌리기</button>
        <button class="btn btn-text yt-layer-clear" type="button">비우고 새로 쓰기</button>
      </span>
    </div>
    <p class="note note-danger small yt-layer-problems" hidden></p>
    <button class="btn btn-primary btn-block yt-post" type="button">게시</button>
  </div>

  <!-- 3) 결과 -->
  <div class="yt-step" data-step="done" hidden>
    <p class="note note-info yt-layer-done">댓글을 올렸습니다.</p>
    <a class="btn btn-secondary btn-block yt-layer-link" href="#" target="_blank" rel="noopener">유튜브에서 내 댓글 보기</a>
  </div>

  <p class="note note-danger small yt-layer-error" hidden></p>
</dialog>
<?php endif; ?>

<script>
(function () {
  // 목록을 누를 때마다 페이지를 다시 받으면 피드 다섯 개를 또 읽게 된다. 미리 그려 둔 상세를 바꿔 끼운다
  var items  = document.querySelectorAll('.yt-item');
  var panels = document.querySelectorAll('.yt-panel');
  var pick   = document.querySelector('.yt-pick');

  function show(id) {
    for (var i = 0; i < items.length; i++) {
      var on = items[i].getAttribute('data-video') === id;
      items[i].classList.toggle('on', on);
      if (on) { items[i].setAttribute('aria-current', 'true'); } else { items[i].removeAttribute('aria-current'); }
    }
    for (var j = 0; j < panels.length; j++) {
      panels[j].hidden = panels[j].getAttribute('data-video') !== id;
    }
    if (pick) { pick.hidden = true; }
  }

  for (var i = 0; i < items.length; i++) {
    items[i].addEventListener('click', function (e) {
      e.preventDefault();
      var id = this.getAttribute('data-video');
      show(id);
      history.replaceState(null, '', 'youtube_comments.php?load=1&v=' + encodeURIComponent(id));
      // 한 칸으로 접힌 폰 화면에서는 상세가 목록 아래에 있어 눌러도 바뀐 게 안 보인다
      if (window.matchMedia('(max-width: 860px)').matches) {
        document.getElementById('detail').scrollIntoView({ behavior: 'smooth' });
      }
    });
  }

  // 초안을 고쳐 쓰는 동안 글자 수를 따라가게 한다. 기준 판정은 서버 결과만 쓴다(규칙을 두 곳에 두지 않는다)
  document.addEventListener('input', function (e) {
    // 레이어의 입력칸도 .yt-draft 라 여기로 온다. 그쪽 글자 수는 레이어 스크립트가 센다
    var panel = e.target.classList.contains('yt-draft') ? e.target.closest('.yt-panel') : null;
    if (!panel) { return; }
    var count = panel.querySelector('.yt-count');
    count.textContent = Array.from(e.target.value.trim()).length;
  });

  document.addEventListener('click', function (e) {
    if (!e.target.classList.contains('yt-copy')) { return; }
    var btn  = e.target;
    var area = btn.closest('.yt-panel').querySelector('.yt-draft');
    var done = function () { btn.textContent = '복사됨'; setTimeout(function () { btn.textContent = '복사'; }, 1500); };
    if (navigator.clipboard) {
      navigator.clipboard.writeText(area.value).then(done, function () { area.select(); });
    } else {
      area.select();
      document.execCommand('copy');
      done();
    }
  });
})();
</script>

<?php if ($canPost): ?>
<script>
(function () {
  var CLIENT_ID = <?= json_encode(YT_OAUTH_CLIENT_ID) ?>;
  // 댓글 쓰기에 필요한 가장 좁은 범위다. youtube.readonly 로는 댓글을 올릴 수 없다
  var SCOPE = 'https://www.googleapis.com/auth/youtube.force-ssl';
  var API   = 'https://www.googleapis.com/youtube/v3/';

  var layer   = document.querySelector('.yt-layer');
  var errBox  = layer.querySelector('.yt-layer-error');
  var probBox = layer.querySelector('.yt-layer-problems');
  var postBtn = layer.querySelector('.yt-post');
  var textBox = layer.querySelector('.yt-layer-text');
  var countBox = layer.querySelector('.yt-layer-count');
  var accHead = document.querySelector('.yt-account');
  var outBtn  = document.querySelector('.yt-signout');

  var token   = null;   // 메모리에만 둔다. 저장소에 넣으면 창을 닫은 뒤에도 남의 PC 에 계정 권한이 남는다
  var expires = 0;
  var channel = '';
  var current = null;   // { id, title, panel } — 올릴 글은 게시를 누르는 순간 레이어 입력칸에서 읽는다
  var client  = null;
  var posted  = {};     // 한 영상에 같은 사람이 두 번 올리지 않게(버튼 연타·레이어 재오픈)

  function step(name) {
    var steps = layer.querySelectorAll('.yt-step');
    for (var i = 0; i < steps.length; i++) { steps[i].hidden = steps[i].getAttribute('data-step') !== name; }
  }
  function fail(msg) { errBox.textContent = msg; errBox.hidden = !msg; }

  function api(method, path, body) {
    return fetch(API + path, {
      method: method,
      headers: { 'Authorization': 'Bearer ' + token, 'Content-Type': 'application/json' },
      body: body ? JSON.stringify(body) : undefined
    }).then(function (res) {
      return res.json().catch(function () { return {}; }).then(function (data) {
        if (res.ok) { return data; }
        var reason = data.error && data.error.errors && data.error.errors[0] ? data.error.errors[0].reason : '';
        var err = new Error(reason || ('HTTP ' + res.status));
        err.status = res.status; err.reason = reason;
        throw err;
      });
    });
  }

  // 유튜브가 돌려주는 거절 사유를 사람이 할 일로 바꾼다. 원문 메시지는 영어이고 뜻이 바로 안 읽힌다
  function explain(err) {
    switch (err.reason) {
      case 'commentsDisabled':        return '이 영상은 댓글이 막혀 있습니다.';
      case 'quotaExceeded':           return '오늘 사용할 수 있는 유튜브 API 한도를 다 썼습니다. 내일 다시 시도해 주세요.';
      // 로그인은 되는데 게시만 실패하는 경우라 원인을 짐작하기 어렵다. 콘솔에서 할 일을 그대로 적어 준다
      case 'accessNotConfigured':
      case 'SERVICE_DISABLED':        return 'Google Cloud 프로젝트에서 YouTube Data API v3 가 꺼져 있습니다. 콘솔의 API 라이브러리에서 "사용"을 누른 뒤 몇 분 뒤 다시 시도해 주세요.';
      case 'insufficientPermissions':
      case 'ACCESS_TOKEN_SCOPE_INSUFFICIENT':
                                      return '댓글 쓰기 권한을 허용하지 않았습니다. 다시 로그인해 권한을 허용해 주세요.';
      case 'forbidden':               return '이 계정으로는 이 영상에 댓글을 달 수 없습니다.';
      case 'processingFailure':       return '유튜브가 댓글을 처리하지 못했습니다. 잠시 뒤 다시 시도해 주세요.';
    }
    if (err.status === 401) { return '로그인이 만료되었습니다. 다시 로그인해 주세요.'; }
    if (!err.status)        { return '유튜브에 연결하지 못했습니다. 네트워크를 확인해 주세요.'; }
    return '댓글을 올리지 못했습니다(' + err.message + ').';
  }

  function signedIn() { return token && Date.now() < expires; }

  function setAccount(name) {
    channel = name;
    accHead.textContent = name ? name + ' (유튜브)' : '';
    accHead.hidden = outBtn.hidden = !name;
    layer.querySelector('.yt-layer-account').textContent = name;
  }

  // 로그인한 계정의 유튜브 채널. 채널이 없는 Google 계정은 댓글을 달 수 없어 여기서 먼저 걸러 준다
  function loadChannel() {
    return api('GET', 'channels?part=snippet&mine=true').then(function (data) {
      if (!data.items || !data.items.length) {
        throw { reason: 'noChannel' };
      }
      setAccount(data.items[0].snippet.title);
    });
  }

  function len(s) { return Array.from(s.trim()).length; }

  function setText(value) {
    textBox.value = value;
    countBox.textContent = len(value);
    probBox.hidden = true;
    // 화면의 초안 칸도 같이 바꿔 둔다. 레이어를 닫았다 다시 열거나 [복사] 할 때 고친 글이 남아 있게
    var area = current.panel.querySelector('.yt-draft');
    area.value = value;
    current.panel.querySelector('.yt-count').textContent = len(value);
  }

  function toConfirm() {
    fail('');
    step('confirm');
    setText(current.panel.querySelector('.yt-draft').value);
    postBtn.textContent = '게시';
    postBtn.disabled = !!posted[current.id];
    if (posted[current.id]) {
      probBox.textContent = '이 영상에는 이미 댓글을 올렸습니다.';
      probBox.hidden = false;
    }
  }

  // 게시는 서버를 거친다. 한도(영상당·하루)와 초안 기준을 서버가 잠금 안에서 보고 대신 올린다.
  // 토큰은 이 요청 본문에만 실린다(주소에 넣으면 서버 접근 로그에 남는다)
  function send(text) {
    var form = new FormData();
    form.append('action', 'post');
    form.append('v', current.id);
    form.append('text', text);
    form.append('token', token);

    return fetch('youtube_comments.php', { method: 'POST', body: form })
      .then(function (res) { return res.json(); });
  }

  function setLeft(n) {
    var left = document.querySelector('.yt-left');
    if (left && typeof n === 'number') { left.textContent = n; }
    if (n === 0) {
      var opens = document.querySelectorAll('.yt-post-open');
      for (var i = 0; i < opens.length; i++) { opens[i].disabled = true; }
    }
  }

  function ensureClient() {
    if (client) { return true; }
    if (!window.google || !google.accounts || !google.accounts.oauth2) {
      fail('Google 로그인 모듈을 불러오지 못했습니다. 광고 차단 확장 기능이 막고 있지 않은지 확인해 주세요.');
      return false;
    }
    client = google.accounts.oauth2.initTokenClient({
      client_id: CLIENT_ID,
      scope: SCOPE,
      callback: function (resp) {
        if (resp.error) { fail('로그인하지 못했습니다(' + resp.error + ').'); return; }
        if (!google.accounts.oauth2.hasGrantedAllScopes(resp, SCOPE)) {
          fail('댓글 쓰기 권한을 허용해야 댓글을 달 수 있습니다. 다시 로그인해 권한에 체크해 주세요.');
          return;
        }
        token   = resp.access_token;
        expires = Date.now() + (parseInt(resp.expires_in, 10) - 60) * 1000;
        loadChannel().then(toConfirm, function (err) {
          token = null;
          fail(err.reason === 'noChannel'
            ? '이 Google 계정에는 유튜브 채널이 없어 댓글을 달 수 없습니다. 유튜브에서 채널을 먼저 만들어 주세요.'
            : explain(err));
        });
      },
      error_callback: function (e) {
        // 사용자가 팝업을 닫았을 때도 여기로 온다. 그건 오류가 아니므로 조용히 로그인 단계에 둔다
        if (e && e.type === 'popup_failed_to_open') {
          fail('로그인 창이 차단되었습니다. 브라우저에서 이 사이트의 팝업을 허용해 주세요.');
        }
      }
    });
    return true;
  }

  document.addEventListener('click', function (e) {
    if (!e.target.classList.contains('yt-post-open')) { return; }
    var panel = e.target.closest('.yt-panel');
    current = {
      id: panel.getAttribute('data-video'),
      title: panel.getAttribute('data-title'),
      panel: panel
    };
    layer.querySelector('.yt-layer-video').textContent = current.title;
    fail('');
    if (signedIn()) { toConfirm(); } else { step('login'); }
    layer.showModal();
  });

  layer.querySelector('.yt-signin').addEventListener('click', function () {
    fail('');
    // 팝업은 클릭 처리 안에서 바로 열어야 브라우저가 막지 않는다
    if (ensureClient()) { client.requestAccessToken(); }
  });

  textBox.addEventListener('input', function () { setText(textBox.value); });

  layer.querySelector('.yt-layer-reset').addEventListener('click', function () {
    // defaultValue 는 서버가 그린 처음 초안이다. 화면 칸을 고쳐 둔 뒤라도 원래 글로 돌아간다
    setText(current.panel.querySelector('.yt-draft').defaultValue);
    textBox.focus();
  });
  layer.querySelector('.yt-layer-clear').addEventListener('click', function () {
    setText('');
    textBox.focus();
  });

  postBtn.addEventListener('click', function () {
    if (!signedIn()) { token = null; step('login'); fail('로그인이 만료되었습니다. 다시 로그인해 주세요.'); return; }
    if (posted[current.id]) { return; }

    var text = textBox.value.trim();
    postBtn.disabled = true;
    textBox.readOnly = true;
    postBtn.textContent = '올리는 중…';
    probBox.hidden = true;
    fail('');

    var reset = function () { postBtn.textContent = '게시'; postBtn.disabled = false; textBox.readOnly = false; };

    send(text).then(function (r) {
      setLeft(r.left);
      if (r.ok) {
        posted[current.id] = true;
        textBox.readOnly = false;
        var open = current.panel.querySelector('.yt-post-open');
        if (open) { open.hidden = true; }
        layer.querySelector('.yt-layer-link').href =
          'https://www.youtube.com/watch?v=' + encodeURIComponent(current.id) + '&lc=' + encodeURIComponent(r.id);
        step('done');
        return;
      }
      reset();
      if (r.problems) {
        probBox.textContent = '이대로는 올릴 수 없습니다: ' + r.problems.join(' / ');
        probBox.hidden = false;
        return;
      }
      if (r.status === 401) { token = null; step('login'); }
      fail(explain({ status: r.status, reason: r.reason, message: r.reason || ('HTTP ' + r.status) }));
    }, function () {
      reset();
      fail('서버에 연결하지 못했습니다. 잠시 뒤 다시 시도해 주세요.');
    });
  });

  layer.querySelector('.yt-layer-close').addEventListener('click', function () { layer.close(); });
  // 레이어 바깥(배경)을 누르면 닫는다
  layer.addEventListener('click', function (e) { if (e.target === layer) { layer.close(); } });

  outBtn.addEventListener('click', function () {
    if (token && window.google) { google.accounts.oauth2.revoke(token, function () {}); }
    token = null; expires = 0;
    setAccount('');
  });
})();
</script>
<?php endif; ?>
</body>
</html>
