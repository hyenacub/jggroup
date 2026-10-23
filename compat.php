<?php
/* ============================================================
 *  PHP 7.0 호환 보완
 *
 *  배포 서버(카페24)의 PHP 가 7.0 이라 7.3/8.0 에서 들어온 내장 함수가 없다.
 *  개발 장비는 8.0 이므로 양쪽에서 같은 코드가 돌아야 한다. 그래서 "없을 때만" 채운다.
 *  function_exists 검사를 빼면 PHP 8 에서 "이미 선언된 함수" 오류로 죽는다.
 *
 *  문법(화살표 함수, match, 널 허용 타입 등)은 이 파일로 메울 수 없어 코드 쪽을 7.0 문법으로 맞췄다.
 *  나중에 서버가 8 로 올라가면 이 파일은 그대로 두어도 아무 일도 하지 않는다.
 * ============================================================ */

if (!function_exists('str_contains')) {
    /** PHP 8.0 추가. 빈 바늘은 항상 true 라는 원래 규약까지 맞춘다. */
    function str_contains(string $haystack, string $needle): bool
    {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}

if (!function_exists('array_key_first')) {
    /** PHP 7.3 추가. 빈 배열이면 null. */
    function array_key_first(array $array)
    {
        foreach ($array as $key => $_) {
            return $key;
        }
        return null;
    }
}

if (!function_exists('str_starts_with')) {
    /** PHP 8.0 추가. 지금은 쓰지 않지만 같은 성격이라 함께 둔다. */
    function str_starts_with(string $haystack, string $needle): bool
    {
        return strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}
