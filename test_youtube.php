<?php
/**
 * 유튜브 영상 목록 · 댓글 초안 검사 (CLI 전용)
 *
 *   "C:\xampp\php\php.exe" "D:\Orca\test_youtube.php"
 *
 * 네트워크를 쓰지 않는다. 채널 피드는 올라오는 영상에 따라 매일 달라져 고정할 수 없으므로,
 * 같은 모양의 RSS 를 여기서 만들어 파싱만 본다. 실제 목록은 화면의 [영상 불러오기]로 확인한다.
 *
 * 초안은 youtube_drafts.php 전체를 기준으로 돌린다. 초안을 고치거나 더한 뒤 이 테스트가
 * 실패하면 그 초안이 기준(40~120자, 링크·해시태그·운명원 후기 금지, 영상 속 장면 언급)을 어긴 것이다.
 *
 * 한글이 깨져 보이면 먼저 chcp 65001 로 콘솔 코드페이지를 UTF-8 로 바꾼다.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI 전용 스크립트입니다.');
}

require_once __DIR__ . '/youtube.php';

$results = [];
function check($desc, $ok, $detail = '')
{
    global $results;
    $results[] = [$ok, $desc, $detail];
}

/* ---------------- 피드 파싱 ---------------- */
$xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<feed xmlns:yt="http://www.youtube.com/xml/schemas/2015" xmlns:media="http://search.yahoo.com/mrss/" xmlns="http://www.w3.org/2005/Atom">
 <title>테스트 채널</title>
 <entry>
  <yt:videoId>AAAAAAAAAAA</yt:videoId>
  <yt:channelId>UCtest</yt:channelId>
  <title>새 영상 &amp; 제목</title>
  <author><name>피드 이름</name></author>
  <published>2026-10-21T10:15:40+00:00</published>
  <media:group><media:thumbnail url="https://i1.ytimg.com/vi/AAAAAAAAAAA/hqdefault.jpg" width="480" height="360"/></media:group>
 </entry>
 <entry>
  <yt:videoId>BBBBBBBBBBB</yt:videoId>
  <title>썸네일 없는 영상</title>
  <published>2026-10-20T10:15:40+00:00</published>
 </entry>
 <entry><title>ID 없는 항목</title></entry>
</feed>
XML;

$v = yt_parse_feed($xml, '대체 이름');
check('ID 없는 항목은 건너뛰고 2건만 읽는다', count($v) === 2, 'count=' . count($v));
check('제목의 엔티티를 풀어서 읽는다', isset($v[0]) && $v[0]['title'] === '새 영상 & 제목');
check('올린 시각을 UTC 기준 타임스탬프로 읽는다',
      isset($v[0]) && $v[0]['published'] === gmmktime(10, 15, 40, 10, 21, 2026));
check('피드의 썸네일 주소를 쓴다',
      isset($v[0]) && $v[0]['thumb'] === 'https://i1.ytimg.com/vi/AAAAAAAAAAA/hqdefault.jpg');
check('썸네일이 없으면 영상 ID 로 주소를 만든다',
      isset($v[1]) && $v[1]['thumb'] === 'https://i.ytimg.com/vi/BBBBBBBBBBB/hqdefault.jpg');
check('작성자가 없으면 넘겨준 채널 이름을 쓴다', isset($v[1]) && $v[1]['channel'] === '대체 이름');
check('영상 주소를 만든다', isset($v[0]) && $v[0]['url'] === 'https://www.youtube.com/watch?v=AAAAAAAAAAA');
check('깨진 XML 은 빈 배열(경고 없이)', yt_parse_feed('<feed><entry>') === []);
check('채널은 다섯 개', count(YT_CHANNELS) === 5);

/* ---------------- 초안 검사 함수 ---------------- */
$ok = ['draft' => '탕비실에서 "오늘 점심 드셨어요?"에 "네, 먹었어요"로 끝나 버리는 장면이 딱 저예요. 덜 긴장될 것 같아요.', 'cue' => '탕비실'];
check('기준을 지킨 초안은 통과', yt_draft_problems($ok) === [], implode(' / ', yt_draft_problems($ok)));

$cases = [
    '39자는 짧다'                => [str_repeat('가', 36) . '탕비실', '글자 수'],
    '121자는 길다'               => [str_repeat('가', 118) . '탕비실', '글자 수'],
    'http 링크'                  => ['탕비실 장면이 좋았어요. 자세한 건 https://example.com 에서 보세요. 정말 공감이 많이 됐습니다.', '링크'],
    'http 없는 도메인도 링크'     => ['탕비실 장면이 좋았어요. 자세한 건 unmyungwon.co.kr 에서 보세요. 정말 공감이 많이 됐습니다.', '링크'],
    'youtu.be 단축 주소'          => ['탕비실 장면이 좋았어요. 비슷한 영상 youtu.be/abc 도 같이 보면 정말 공감이 많이 됩니다.', '링크'],
    '해시태그'                    => ['탕비실 장면이 좋았어요. 저도 늘 이랬거든요. 정말 공감이 많이 됐습니다 #연애심리 #공감', '해시태그'],
    '전각 해시태그'               => ['탕비실 장면이 좋았어요. 저도 늘 이랬거든요. 정말 공감이 많이 됐습니다 ＃연애심리', '해시태그'],
    '운명원 이름'                 => ['탕비실 장면 공감돼요. 저도 운명원에서 비슷한 이야기를 들었는데 정말 공감이 많이 됐습니다.', '운명원'],
    '이름 없이 지어낸 사주 경험'   => ['탕비실 장면 공감돼요. 저도 연애 사주 봤더니 올해 인연이 온다고 해서 정말 공감이 많이 됐습니다.', '이용 후기'],
    '결과 장담'                   => ['탕비실 장면 공감돼요. 연애 사주로 보면 그 사람 마음이 반드시 보인대요. 궁금하신 분들 한번 보세요.', '장담'],
    '이름 없이 꾸민 이용 후기'     =>['탕비실 장면 공감돼요. 저도 상담 받아 보고 마음이 편해졌는데 정말 공감이 많이 됐습니다.', '이용 후기'],
    '영상 속 장면을 안 짚음'       => ['좋은 영상 감사합니다. 늘 잘 보고 있어요. 오늘도 많이 배우고 갑니다. 다음 영상도 기대할게요.', '영상 속 장면'],
];
foreach ($cases as $desc => $case) {
    list($text, $expect) = $case;
    $p   = yt_draft_problems(['draft' => $text, 'cue' => '탕비실']);
    $hit = false;
    foreach ($p as $msg) {
        $hit = $hit || mb_strpos($msg, $expect) !== false;
    }
    check('잡아낸다: ' . $desc, $hit, '결과: ' . ($p === [] ? '(통과해 버림)' : implode(' / ', $p)));
}
check('40자·120자 경계는 통과',
      yt_draft_problems(['draft' => str_repeat('가', 37) . '탕비실', 'cue' => '탕비실']) === []
      && yt_draft_problems(['draft' => str_repeat('가', 117) . '탕비실', 'cue' => '탕비실']) === []);
check('사주가 궁금하다는 말까지는 통과',
      yt_draft_problems(['draft' => '탕비실 장면 완전 저예요ㅋㅋ 좋아하는 사람 앞에서만 굳는 것도 타고난 건가 싶어 사주라도 보고 싶네요.', 'cue' => '탕비실']) === []);
check('cue 가 비면 실패', yt_draft_problems(['draft' => $ok['draft'], 'cue' => '']) !== []);

// 레이어에서 새로 쓴 글: 장면 언급은 보지 않지만 나머지 기준은 그대로다
$own = '저도 좋아하는 사람 앞에서는 말이 꼬여서 늘 후회했는데, 오늘 영상 보고 조금 용기가 생겼어요. 감사합니다.';
check('새로 쓴 글은 장면 언급 없이도 통과', yt_draft_problems(['draft' => $own, 'cue' => '탕비실'], false) === [],
      implode(' / ', yt_draft_problems(['draft' => $own, 'cue' => '탕비실'], false)));
check('새로 쓴 글도 링크는 잡아낸다',
      yt_draft_problems(['draft' => $own . ' youtu.be/abc', 'cue' => '탕비실'], false) !== []);
check('새로 쓴 글도 글자 수를 본다', yt_draft_problems(['draft' => '좋아요', 'cue' => ''], false) !== []);

/* ---------------- 게시 한도 ---------------- */
// 기록 배열만으로 본다. 실제 게시는 남의 영상에 댓글이 달리므로 테스트로 부르지 않는다
$today = '2026-10-23';
$log   = [['video' => 'AAA', 'comment' => 'c1', 'day' => $today, 'at' => 0]];
check('처음 다는 영상은 통과', yt_post_limit_problem([], 'AAA', $today) === null);
check('같은 영상 두 번째는 막는다', yt_post_limit_problem($log, 'AAA', $today) !== null);
check('어제 단 영상도 다시는 못 단다(영상당 평생 1개)',
      yt_post_limit_problem([['video' => 'AAA', 'day' => '2026-10-22']], 'AAA', $today) !== null);

$nine = [];
for ($i = 0; $i < YT_POST_PER_DAY - 1; $i++) {
    $nine[] = ['video' => 'V' . $i, 'day' => $today];
}
check('오늘 9개까지는 10번째를 허용', yt_post_limit_problem($nine, 'NEW', $today) === null);
$ten   = array_merge($nine, [['video' => 'V9', 'day' => $today]]);
$p     = yt_post_limit_problem($ten, 'NEW', $today);
check('오늘 10개를 달면 11번째는 막는다', $p !== null && mb_strpos($p, '오늘') !== false, (string)$p);
check('어제 10개는 오늘 한도에 안 센다', yt_post_limit_problem($ten, 'NEW', '2026-10-24') === null);
check('한도는 영상당 1개 · 하루 10개', YT_POST_PER_VIDEO === 1 && YT_POST_PER_DAY === 10);

// 기준에 걸리는 글은 한도를 보기 전에(= 유튜브를 부르기 전에) 돌려보낸다. 토큰이 가짜여도 네트워크로 나가지 않는다
$drafts0 = yt_drafts();
$anyId   = (string)array_key_first($drafts0);
$r       = yt_post_guarded($anyId, '링크 넣은 글 https://example.com 입니다. 정말 공감이 많이 됐어요. 다음에도 볼게요.', 'FAKE', $drafts0);
check('기준 위반 글은 올리지 않는다', $r['ok'] === false && !empty($r['problems']));
$r = yt_post_guarded('NO_SUCH_VIDEO', $ok['draft'], 'FAKE', $drafts0);
check('초안이 없는 영상은 올리지 않는다', $r['ok'] === false && !empty($r['problems']));

/* ---------------- 실제 초안 전체 ---------------- */
$drafts = yt_drafts();
check('초안 파일을 읽는다', count($drafts) > 0, 'count=' . count($drafts));

foreach ($drafts as $id => $d) {
    $p = yt_draft_problems($d);
    check("초안 {$id} 기준 통과", $p === [], implode(' / ', $p));
    check("초안 {$id} 고민 한 줄이 있다", trim((string)($d['worry'] ?? '')) !== ''
          && mb_strpos((string)$d['worry'], "\n") === false);
}

// "영상마다 다르게" — 같은 문장을 돌려쓰지 않았는지, 같은 장면을 두 영상에 쓰지 않았는지
$texts = array_column($drafts, 'draft');
$cues  = array_column($drafts, 'cue');
check('초안이 영상마다 다르다', count(array_unique($texts)) === count($texts));
check('언급한 장면이 영상마다 다르다', count(array_unique($cues)) === count($cues));

// 첫 문장 앞머리가 같으면 복붙한 티가 난다
$heads = [];
foreach ($texts as $t) {
    $heads[] = mb_substr($t, 0, 12);
}
check('초안 앞머리 12자가 겹치지 않는다', count(array_unique($heads)) === count($heads));

/* ---------------- 결과 ---------------- */
$passed = 0;
foreach ($results as $i => $row) {
    list($ok, $desc, $detail) = $row;
    printf("[%s] %d. %s%s", $ok ? 'PASS' : 'FAIL', $i + 1, $desc, PHP_EOL);
    if (!$ok && $detail !== '') {
        printf("       %s%s", $detail, PHP_EOL);
    }
    $passed += $ok ? 1 : 0;
}

echo PHP_EOL, str_repeat('-', 72), PHP_EOL;
$total = count($results);
printf('%s  %d / %d 통과%s', $passed === $total ? '전체 통과' : '실패 있음', $passed, $total, PHP_EOL);

exit($passed === $total ? 0 : 1);
