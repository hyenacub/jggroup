<?php
/* ============================================================
 *  유튜브 영상 호출 — 채널 최신 영상 목록 + 댓글 초안 검사
 *
 *  YouTube Data API 대신 채널 RSS(/feeds/videos.xml)를 읽는다. 키가 필요 없고 할당량도 없다.
 *  Data API 는 검색 한 번에 할당량 100을 쓰고 키를 .env 에 둬야 하는데, 여기서 필요한 것은
 *  "채널마다 최근 영상 몇 개의 제목·썸네일·날짜" 뿐이라 RSS 로 충분하다.
 *  대신 RSS 는 채널당 최근 15개만 주고 조회수·길이는 없다.
 *
 *  댓글은 사용자가 레이어에서 자기 유튜브(Google) 계정으로 로그인한 토큰으로 올린다. 브라우저가 그 토큰을
 *  게시 요청 한 건에 실어 보내면 서버가 한도(영상당 1개·하루 10개)를 확인하고 대신 올린다
 *  (yt_post_guarded()). 브라우저가 유튜브를 직접 부르게 두면 한도를 서버가 지킬 방법이 없다.
 *  토큰은 그 요청 안에서만 쓰고 파일·세션·로그 어디에도 남기지 않는다 — 남의 계정으로 글을 쓸 수 있는
 *  값을 우리가 쥐고 있으면 유출 시 그대로 도용된다.
 * ============================================================ */
require_once __DIR__ . '/env.php';

/**
 * 채널 목록. .env 의 '유튜브 영상 호출 및 댓글작성' 절과 같은 값이다.
 * 그 절은 KEY=VALUE 가 아닌 메모 형식이라 env() 로 읽을 수 없어 여기에 옮겨 적었다.
 * 채널을 바꾸면 두 곳을 같이 고친다.
 */
define('YT_CHANNELS', [
    'UCxlc0Ky0aWoD8uI09ad44Tg' => '외롭여',
    'UC-ee_ziASM251TMXe3P3VZA' => '리즈시절 LizTV',
    'UC09L3JxGVcn7SKLa-pjTEmw' => '러브커넥트 유지연',
    'UCVdtDW-HF4KluixGaBAcI4w' => '연애데이터 노노',
    'UCkGl2jieOVhGiHPdXF77Yyw' => '심리조각',
]);

define('YT_FEED_URL', 'https://www.youtube.com/feeds/videos.xml?channel_id=');
define('YT_PER_CHANNEL', 3);      // 채널마다 목록에 올릴 최신 영상 수
define('YT_TIMEOUT', 10);
// 영상을 고를 때마다 피드 다섯 개를 다시 받지 않으려는 캐시. [영상 불러오기] 는 캐시를 건너뛴다
define('YT_CACHE_TTL', 600);

// [영상 불러오기] 를 눌러도 이 시간 안에 받은 피드는 다시 받지 않는다. 로그인 없이 열리는 화면이라
// 버튼을 연타하거나 ?fresh=1 을 반복 호출하면 그만큼 유튜브로 요청이 나가 우리 서버 IP 가 막힐 수 있다
define('YT_FRESH_MIN', 60);

/**
 * 댓글 달기용 Google OAuth 클라이언트 ID(웹 애플리케이션 유형). 비어 있으면 [댓글 달기] 가 꺼진다.
 * 브라우저로 내려가는 공개 값이라 비밀이 아니다. 클라이언트 보안 비밀(secret)은 쓰지 않는다 —
 * 토큰을 브라우저에서 바로 받는 방식이라 서버가 코드를 토큰으로 바꿀 일이 없다.
 */
define('YT_OAUTH_CLIENT_ID', (string)env('YOUTUBE_OAUTH_CLIENT_ID', ''));

define('YT_DRAFT_MIN', 40);
define('YT_DRAFT_MAX', 120);

// 게시 한도. 영상마다 한 개, 하루(한국 시각) 전체 열 개. 같은 글이 여러 영상에 몰리면 유튜브가 스팸으로 묶는다.
// 계정이 아니라 이 화면 전체로 센다 — 계정별로 세면 로그인만 바꿔 한도를 우회한다
define('YT_POST_PER_VIDEO', 1);
define('YT_POST_PER_DAY', 10);
// 게시 기록. 브라우저에 두면 새로고침·다른 브라우저로 한도가 풀리므로 서버 파일에 둔다(토큰·본문은 적지 않는다)
define('YT_POST_LOG', __DIR__ . '/logs/youtube_posts.json');
define('YT_POST_API', 'https://www.googleapis.com/youtube/v3/commentThreads?part=snippet');

/**
 * 채널 하나의 RSS 를 받는다. 실패하면 null — 한 채널이 막혀도 나머지 목록은 보여야 한다.
 */
function yt_fetch_feed(string $channelId, bool $fresh = false)
{
    $cache = sys_get_temp_dir() . '/orca_yt_' . preg_replace('/[^A-Za-z0-9_-]/', '', $channelId) . '.xml';

    $age = is_file($cache) ? time() - filemtime($cache) : PHP_INT_MAX;

    if ($age < ($fresh ? YT_FRESH_MIN : YT_CACHE_TTL)) {
        return (string)file_get_contents($cache);
    }

    $ch = curl_init(YT_FEED_URL . rawurlencode($channelId));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => YT_TIMEOUT,
        CURLOPT_USERAGENT      => 'orca-php/1.0',
    ]);
    $body   = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err    = curl_error($ch);
    curl_close($ch);

    if ($body === false || $status !== 200) {
        error_log("[youtube] 피드 실패 {$channelId}: HTTP {$status} {$err}");

        // 새로 받지 못했으면 오래된 캐시라도 쓴다. 비어 있는 것보다 낫다
        return is_file($cache) ? (string)file_get_contents($cache) : null;
    }

    @file_put_contents($cache, $body);

    return (string)$body;
}

/**
 * RSS 원문을 영상 배열로 바꾼다. 네트워크와 분리해 둔 것은 테스트가 고정 XML 로 돌게 하려는 것.
 *
 * @return array<int, array{id:string, title:string, channel:string, channel_id:string, published:int, thumb:string, url:string}>
 */
function yt_parse_feed(string $xml, string $fallbackChannel = ''): array
{
    $prev = libxml_use_internal_errors(true);
    $feed = simplexml_load_string($xml);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);

    if ($feed === false) {
        return [];
    }

    $videos = [];

    foreach ($feed->entry as $entry) {
        $yt    = $entry->children('http://www.youtube.com/xml/schemas/2015');
        $media = $entry->children('http://search.yahoo.com/mrss/');
        $id    = (string)$yt->videoId;

        if ($id === '') {
            continue;
        }

        $thumb = '';
        if (isset($media->group->thumbnail)) {
            $thumb = (string)$media->group->thumbnail->attributes()->url;
        }

        $videos[] = [
            'id'         => $id,
            'title'      => (string)$entry->title,
            'channel'    => (string)$entry->author->name !== '' ? (string)$entry->author->name : $fallbackChannel,
            'channel_id' => (string)$yt->channelId,
            'published'  => (int)strtotime((string)$entry->published),
            // 피드의 썸네일 주소가 비면 영상 ID 로 만든다(같은 규칙의 주소다)
            'thumb'      => $thumb !== '' ? $thumb : 'https://i.ytimg.com/vi/' . rawurlencode($id) . '/hqdefault.jpg',
            'url'        => 'https://www.youtube.com/watch?v=' . rawurlencode($id),
        ];
    }

    return $videos;
}

/**
 * 다섯 채널의 최신 영상을 모아 최신순으로 돌려준다.
 *
 * $drafts 를 주면 초안이 있는 영상만 남긴다. 초안을 쓰기 어려운 영상(자막 없는 라이브, 내용 없는
 * 광고 쇼츠 등)은 목록에 올려 봐야 할 수 있는 일이 없어서다. 거른 뒤에 채널당 개수를 센다.
 *
 * @return array{0: array, 1: array<string, string>}  [영상 목록, 실패한 채널 id => 이름]
 */
function yt_latest_videos(bool $fresh = false, $drafts = null): array
{
    $all    = [];
    $failed = [];

    foreach (YT_CHANNELS as $channelId => $name) {
        $xml    = yt_fetch_feed($channelId, $fresh);
        $videos = $xml === null ? [] : yt_parse_feed($xml, $name);

        if ($videos === []) {
            $failed[$channelId] = $name;
            continue;
        }

        if (is_array($drafts)) {
            $videos = array_values(array_filter($videos, function ($v) use ($drafts) {
                return isset($drafts[$v['id']]);
            }));
        }

        // 채널 이름은 .env 표기로 맞춘다. 피드의 author 는 채널이 바꾸면 따라 바뀐다
        foreach (array_slice($videos, 0, YT_PER_CHANNEL) as $v) {
            $v['channel'] = $name;
            $all[]        = $v;
        }
    }

    usort($all, function ($a, $b) {
        return $b['published'] - $a['published'];
    });

    return [$all, $failed];
}

/**
 * 댓글 초안이 기준을 어기는 곳을 모두 돌려준다. 빈 배열이면 통과.
 * 화면은 이 결과를 그대로 보여 주고, 테스트는 youtube_drafts.php 전체를 이걸로 돌린다.
 *
 * $needCue 를 끄면 "영상 속 장면 언급" 만 보지 않는다. 사용자가 레이어에서 초안을 지우고 새로 쓴 글은
 * 우리가 고른 장면을 담고 있을 이유가 없다. 그 밖의 기준(링크·해시태그·후기)은 누가 썼든 그대로다 —
 * 게시하는 순간 우리 화면을 거쳐 나간 댓글이 되기 때문이다.
 *
 * @param array{draft?:string, cue?:string} $entry
 * @return string[]
 */
function yt_draft_problems(array $entry, bool $needCue = true): array
{
    $text     = trim((string)($entry['draft'] ?? ''));
    $cue      = trim((string)($entry['cue'] ?? ''));
    $problems = [];

    $len = mb_strlen($text, 'UTF-8');
    if ($len < YT_DRAFT_MIN || $len > YT_DRAFT_MAX) {
        $problems[] = '글자 수 ' . $len . '자 (' . YT_DRAFT_MIN . '~' . YT_DRAFT_MAX . '자여야 함)';
    }

    // 주소는 http 가 없어도 링크로 바뀐다(유튜브가 도메인 모양을 자동으로 링크한다)
    if (preg_match('~https?://|www\.|youtu\.?be|[a-z0-9-]+\.(com|net|org|kr|co|io|ca|me|ly|gl)\b~iu', $text)) {
        $problems[] = '링크가 들어 있음';
    }

    // 전각 ＃ 도 해시태그로 인식된다
    if (preg_match('/[#＃]\S/u', $text)) {
        $problems[] = '해시태그가 들어 있음';
    }

    // 운명원을 써 본 것처럼 꾸민 후기를 막는다. 이름 자체를 넣지 않는 것으로 정했다 —
    // 이름이 들어간 순간 읽는 사람에게는 "써 본 사람의 추천" 으로 읽히기 때문이다
    if (mb_strpos($text, '운명원') !== false) {
        $problems[] = '운명원 언급 (이용 후기로 읽힐 수 있음)';
    }
    // 사주가 결과를 약속하는 것처럼 쓰면 과장 광고다
    foreach (['100%', '반드시', '무조건 맞', '정확히 맞', '소름'] as $word) {
        if (mb_strpos($text, $word) !== false) {
            $problems[] = '결과를 장담하는 표현: "' . $word . '"';
            break;
        }
    }
    // 업체 이름이 없어도 "사주 봤더니 맞더라" 는 지어낸 경험이다. 초안은 궁금증까지만 쓴다
    foreach (['후기', '상담 받아', '상담받아', '이용해 보', '이용해보', '이용해 봤', '추천합니다', '봤더니', '보고 왔'] as $word) {
        if (mb_strpos($text, $word) !== false) {
            $problems[] = '이용 후기처럼 보이는 표현: "' . $word . '"';
            break;
        }
    }

    if (!$needCue) {
        return $problems;
    }

    if ($cue === '') {
        $problems[] = '영상 속 장면·말(cue)이 적혀 있지 않음';
    } elseif (mb_strpos($text, $cue) === false) {
        $problems[] = '초안에 영상 속 장면·말("' . $cue . '")이 없음';
    }

    return $problems;
}

/**
 * 출력 이스케이프와 정적 파일 판번호. auth.php 의 h()/asset() 과 같은 일을 하지만 이름을 따로 둔다 —
 * 이 화면은 로그인·DB 와 무관한데 auth.php 를 당기면 db.php·config.php 까지 딸려 와서,
 * 유튜브 기능만 떼어 쓸 때(별도 저장소) 쓰지도 않는 파일이 같이 있어야 돌아간다.
 * 같은 이름으로 두면 두 파일을 함께 require 하는 순간 "cannot redeclare" 로 멈춘다.
 */
function yt_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function yt_asset(string $path): string
{
    $file    = __DIR__ . '/' . $path;
    $version = is_file($file) ? (string)filemtime($file) : '';

    return yt_h($version === '' ? $path : $path . '?v=' . $version);
}

/** 한국 시각 기준 날짜. 채널과 사용자가 한국 기준이라 "하루" 도 자정(KST)에 넘어간다. */
function yt_today(): string
{
    $d = new DateTime('now', new DateTimeZone('Asia/Seoul'));

    return $d->format('Y-m-d');
}

/**
 * 게시 기록을 읽는다. 파일이 없거나 깨졌으면 빈 기록 — 한도 화면이 오류로 막히지 않게.
 *
 * @return array<int, array{video:string, comment:string, day:string, at:int}>
 */
function yt_post_log(): array
{
    $raw  = @file_get_contents(YT_POST_LOG);
    $rows = $raw === false ? null : json_decode($raw, true);

    return is_array($rows) ? $rows : [];
}

/** 오늘 올린 개수. */
function yt_posts_today(array $log, string $today): int
{
    $n = 0;
    foreach ($log as $row) {
        $n += ($row['day'] ?? '') === $today ? 1 : 0;
    }

    return $n;
}

/** 이 영상에 이미 올린 개수(날짜 무관). */
function yt_posts_for_video(array $log, string $videoId): int
{
    $n = 0;
    foreach ($log as $row) {
        $n += ($row['video'] ?? '') === $videoId ? 1 : 0;
    }

    return $n;
}

/**
 * 한도에 걸리면 사유, 아니면 null. 네트워크·파일과 분리해 두어 테스트가 기록 배열만으로 돈다.
 */
function yt_post_limit_problem(array $log, string $videoId, string $today)
{
    if (yt_posts_for_video($log, $videoId) >= YT_POST_PER_VIDEO) {
        return '이 영상에는 이미 댓글을 달았습니다(영상당 ' . YT_POST_PER_VIDEO . '개).';
    }
    if (yt_posts_today($log, $today) >= YT_POST_PER_DAY) {
        return '오늘은 댓글을 ' . YT_POST_PER_DAY . '개 모두 달았습니다. 내일 다시 시도해 주세요.';
    }

    return null;
}

/**
 * 유튜브에 댓글 한 건을 올린다. 토큰은 이 요청에만 쓰고 어디에도 남기지 않는다.
 *
 * @return array{ok:bool, id?:string, status:int, reason?:string}
 */
function yt_send_comment(string $token, string $videoId, string $text): array
{
    $ch = curl_init(YT_POST_API);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_TIMEOUT        => YT_TIMEOUT,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode([
            'snippet' => ['videoId' => $videoId, 'topLevelComment' => ['snippet' => ['textOriginal' => $text]]],
        ], JSON_UNESCAPED_UNICODE),
    ]);
    $body   = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errno  = curl_errno($ch);
    curl_close($ch);

    if ($body === false) {
        // 토큰이 실린 요청이라 curl 오류 문구도 그대로 남기지 않고 번호만 적는다
        error_log("[youtube] 댓글 전송 실패 {$videoId}: errno={$errno}");

        return ['ok' => false, 'status' => 0, 'reason' => 'network'];
    }

    $data = json_decode((string)$body, true);
    if ($status === 200 && isset($data['id'])) {
        return ['ok' => true, 'id' => (string)$data['id'], 'status' => 200];
    }

    $reason = isset($data['error']['errors'][0]['reason']) ? (string)$data['error']['errors'][0]['reason'] : '';
    error_log("[youtube] 댓글 거절 {$videoId}: HTTP {$status} {$reason}");

    return ['ok' => false, 'status' => $status, 'reason' => $reason];
}

/**
 * 한도·초안 기준을 보고 통과하면 올린 뒤 기록한다.
 *
 * 확인부터 기록까지 파일을 잠근 채로 한다. 잠그지 않으면 [게시] 를 두 탭에서 동시에 누른 두 건이
 * 나란히 한도 검사를 통과해 영상당 한 개가 깨진다(이니시스 쪽에서 SELECT 선확인을 피한 것과 같은 이유).
 * 유튜브 왕복(1초 안팎) 동안 잠금을 쥐고 있지만, 사람이 누르는 버튼이라 기다림이 문제 되지 않는다.
 *
 * @return array{ok:bool, id?:string, problems?:string[], status?:int, reason?:string, left?:int}
 */
function yt_post_guarded(string $videoId, string $text, string $token, array $drafts): array
{
    if (!isset($drafts[$videoId])) {
        return ['ok' => false, 'problems' => ['초안이 없는 영상입니다.']];
    }

    // 장면 언급은 보지 않는다(새로 쓴 글일 수 있다). 링크·해시태그·후기 기준은 그대로
    $problems = yt_draft_problems(['draft' => $text, 'cue' => $drafts[$videoId]['cue']], false);
    if ($problems !== []) {
        return ['ok' => false, 'problems' => $problems];
    }

    if (!is_dir(dirname(YT_POST_LOG))) {
        @mkdir(dirname(YT_POST_LOG), 0700, true);
    }
    $fp = @fopen(YT_POST_LOG, 'c+');
    if ($fp === false || !flock($fp, LOCK_EX)) {
        error_log('[youtube] 게시 기록 파일을 열지 못함');

        // 기록할 수 없으면 한도를 지킬 수 없으므로 올리지 않는다
        return ['ok' => false, 'problems' => ['게시 기록을 열지 못해 올리지 않았습니다.']];
    }

    $raw   = stream_get_contents($fp);
    $log   = json_decode((string)$raw, true);
    $log   = is_array($log) ? $log : [];
    $today = yt_today();

    $limit = yt_post_limit_problem($log, $videoId, $today);
    if ($limit !== null) {
        flock($fp, LOCK_UN);
        fclose($fp);

        return ['ok' => false, 'problems' => [$limit], 'left' => max(0, YT_POST_PER_DAY - yt_posts_today($log, $today))];
    }

    $sent = yt_send_comment($token, $videoId, trim($text));

    if ($sent['ok']) {
        $log[] = ['video' => $videoId, 'comment' => $sent['id'], 'day' => $today, 'at' => time()];
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($log, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        fflush($fp);
    }

    flock($fp, LOCK_UN);
    fclose($fp);

    $sent['left'] = max(0, YT_POST_PER_DAY - yt_posts_today($log, $today));

    return $sent;
}

/** 초안 표. 파일이 깨졌으면 빈 표로 — 목록 화면은 초안 없이도 떠야 한다. */
function yt_drafts(): array
{
    $drafts = @include __DIR__ . '/youtube_drafts.php';

    return is_array($drafts) ? $drafts : [];
}
