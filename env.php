<?php
/**
 * .env 로더.
 *
 * Composer 를 쓰지 않는 프로젝트라 vlucas/phpdotenv 대신 직접 읽는다.
 * 필요한 만큼만 구현했다: 주석, 따옴표, 값 안의 = 기호, export 접두사.
 *
 * 값을 putenv() 로 내보내지 않고 이 파일 안의 배열에만 담아 둔다.
 * putenv 로 올린 값은 이 PHP 가 띄우는 자식 프로세스에도 그대로 상속돼서,
 * 설정 파일에 적어 둔 비밀번호가 엉뚱한 곳까지 따라갈 수 있다.
 */

// 배포 서버(PHP 7.0)에 없는 내장 함수를 먼저 채운다. 설정 파일들이 모두 이 파일을 거친다.
require_once __DIR__ . '/compat.php';

/**
 * .env 를 읽어 키/값 배열로 돌려준다. 한 요청 안에서는 한 번만 읽는다.
 *
 * 파일이 없어도 오류를 내지 않는다. 그 경우 각 설정 파일의 기본값이 쓰인다.
 * (개발 장비에서 .env 없이도 바로 돌아가야 하기 때문)
 */
function env_load($path = null, bool $reload = false): array
{
    static $vars = null;

    if ($vars !== null && !$reload) {
        return $vars;
    }

    $path = $path ?? __DIR__ . '/.env';
    $vars = [];

    if (!is_readable($path)) {
        return $vars;
    }

    foreach (file($path, FILE_IGNORE_NEW_LINES) as $line) {
        $line = trim($line);

        if ($line === '' || $line[0] === '#') {
            continue;
        }

        if (strpos($line, '=') === false) {
            continue;
        }

        // 값 안에 = 가 들어갈 수 있으므로 첫 번째 = 에서만 자른다.
        list($key, $value) = explode('=', $line, 2);

        $key = trim($key);

        // "export KEY=value" 형태도 받아 준다.
        if (strncmp($key, 'export ', 7) === 0) {
            $key = trim(substr($key, 7));
        }

        if ($key === '') {
            continue;
        }

        $vars[$key] = env_parse_value(trim($value));
    }

    return $vars;
}

/** 따옴표와 줄 끝 주석을 처리한다. */
function env_parse_value(string $value): string
{
    if ($value === '') {
        return '';
    }

    $quote = $value[0];

    if ($quote === '"' || $quote === "'") {
        $end = strrpos($value, $quote);

        if ($end !== false && $end > 0) {
            $inner = substr($value, 1, $end - 1);

            // 큰따옴표 안에서만 \n 같은 이스케이프를 풀어 준다.
            return $quote === '"' ? stripcslashes($inner) : $inner;
        }
    }

    // 따옴표로 감싸지 않았다면 # 뒤는 주석으로 본다.
    $hash = strpos($value, '#');

    if ($hash !== false) {
        $value = substr($value, 0, $hash);
    }

    return trim($value);
}

/**
 * 설정값을 꺼낸다. 키가 없으면 $default.
 *
 * 'true' / 'false' / 'null' 은 문자열이 아니라 해당 타입으로 돌려준다.
 * 빈 값('')은 기본값으로 대체하지 않는다. DB_PASS= 처럼
 * "비어 있음"이 의도된 설정이 있기 때문.
 *
 * @return string|bool|null
 */
function env(string $key, $default = null)
{
    $vars = env_load();

    if (!array_key_exists($key, $vars)) {
        return $default;
    }

    switch (strtolower($vars[$key])) {
        case 'true':  return true;
        case 'false': return false;
        case 'null':  return null;
    }

    return $vars[$key];
}

/** 반드시 있어야 하는 값. 없으면 즉시 멈춘다. */
function env_required(string $key): string
{
    $vars = env_load();

    if (!array_key_exists($key, $vars) || $vars[$key] === '') {
        throw new RuntimeException(".env 에 {$key} 가 필요합니다.");
    }

    return $vars[$key];
}

function env_int(string $key, int $default = 0): int
{
    $value = env($key);

    return is_string($value) && $value !== '' ? (int)$value : $default;
}

function env_bool(string $key, bool $default = false): bool
{
    $value = env($key);

    return is_bool($value) ? $value : $default;
}

/** 콤마로 구분된 목록을 배열로. 빈 항목은 버린다. */
function env_list(string $key, array $default = []): array
{
    $value = env($key);

    if (!is_string($value) || trim($value) === '') {
        return $default;
    }

    return array_values(array_filter(array_map('trim', explode(',', $value)), 'strlen'));
}

/**
 * 경로 설정을 절대경로로 만든다.
 *
 * .env 에는 'logs/inicis_noti.log' 처럼 짧게 적고 싶지만,
 * 상대경로는 실행 위치(CLI 냐 웹서버냐)에 따라 다른 곳을 가리킨다.
 * 프로젝트 폴더 기준으로 고정한다.
 */
function env_path(string $key, string $default): string
{
    $value = env($key);
    $value = is_string($value) && $value !== '' ? $value : $default;

    // 윈도우의 'D:\...' 와 유닉스의 '/...' 를 모두 절대경로로 본다.
    $isAbsolute = preg_match('#^([A-Za-z]:[\\\\/]|[\\\\/])#', $value) === 1;

    return $isAbsolute ? $value : __DIR__ . DIRECTORY_SEPARATOR . $value;
}
