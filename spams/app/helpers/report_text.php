<?php

function report_short_text(string $value, int $limit = 180): string
{
    $text = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    if ($text === '' || $limit <= 0) {
        return $text;
    }

    $length = function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
    if ($length <= $limit) {
        return $text;
    }

    $slice = function_exists('mb_substr') ? mb_substr($text, 0, $limit) : substr($text, 0, $limit);
    $slice = rtrim((string) $slice);

    $spacePos = function_exists('mb_strrpos') ? mb_strrpos($slice, ' ') : strrpos($slice, ' ');
    if ($spacePos !== false && $spacePos > max(24, (int) floor($limit * 0.6))) {
        $slice = function_exists('mb_substr') ? mb_substr($text, 0, (int) $spacePos) : substr($text, 0, (int) $spacePos);
        $slice = rtrim((string) $slice);
    }

    return $slice . '...';
}
