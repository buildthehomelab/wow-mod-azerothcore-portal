<?php
/**
 * Bencoding (BEP 3) for the tracker's answers and the per-player .torrent files.
 **/

declare(strict_types=1);

/** Bytes that bencode() writes out as they are: a torrent's original info dictionary, so its hash stays the same. */
final class BencodeRaw
{
    public function __construct(public readonly string $bytes)
    {
    }
}

/** Lists are PHP lists; any other array is a dictionary, written with its keys sorted as raw bytes. */
function bencode(mixed $value): string
{
    if ($value instanceof BencodeRaw) {
        return $value->bytes;
    }
    if (is_int($value)) {
        return 'i' . $value . 'e';
    }
    if (is_string($value)) {
        return strlen($value) . ':' . $value;
    }
    if (is_array($value)) {
        if (array_is_list($value)) {
            return 'l' . implode('', array_map('bencode', $value)) . 'e';
        }
        $keys = array_map('strval', array_keys($value));
        sort($keys, SORT_STRING);
        $out = 'd';
        foreach ($keys as $key) {
            $out .= bencode($key) . bencode($value[$key]);
        }
        return $out . 'e';
    }
    throw new InvalidArgumentException('Only ints, strings and arrays can be bencoded.');
}

/**
 * Reads one bencoded value at $pos and moves $pos past it. Returns null on malformed input.
 * $infoSpan collects where a top-level "info" value starts and ends.
 */
function bdecode_value(string $data, int &$pos, int $depth = 0, ?array &$infoSpan = null): mixed
{
    $length = strlen($data);
    if ($pos >= $length || $depth > 64) {
        return null;
    }

    $type = $data[$pos];
    if ($type === 'i') {
        $end = strpos($data, 'e', $pos);
        if ($end === false || !preg_match('/^-?\d+$/', substr($data, $pos + 1, $end - $pos - 1))) {
            return null;
        }
        $value = (int)substr($data, $pos + 1, $end - $pos - 1);
        $pos = $end + 1;
        return $value;
    }

    if ($type === 'l' || $type === 'd') {
        $pos++;
        $items = [];
        while ($pos < $length && $data[$pos] !== 'e') {
            if ($type === 'l') {
                $item = bdecode_value($data, $pos, $depth + 1);
                if ($item === null) {
                    return null;
                }
                $items[] = $item;
                continue;
            }

            $key = bdecode_value($data, $pos, $depth + 1);
            if (!is_string($key)) {
                return null;
            }
            $start = $pos;
            $item = bdecode_value($data, $pos, $depth + 1);
            if ($item === null) {
                return null;
            }
            if ($depth === 0 && $key === 'info') {
                $infoSpan = [$start, $pos];
            }
            $items[$key] = $item;
        }
        if ($pos >= $length) {
            return null;
        }
        $pos++;
        return $items;
    }

    if (ctype_digit($type)) {
        $colon = strpos($data, ':', $pos);
        if ($colon === false || !ctype_digit(substr($data, $pos, $colon - $pos))) {
            return null;
        }
        $size = (int)substr($data, $pos, $colon - $pos);
        if ($colon + 1 + $size > $length) {
            return null;
        }
        $value = substr($data, $colon + 1, $size);
        $pos = $colon + 1 + $size;
        return $value;
    }

    return null;
}

/**
 * Parses a .torrent file. raw_info is the info dictionary exactly as stored: its SHA-1 is the info
 * hash, and it's copied as-is into the per-player torrent.
 *
 * @return array{torrent:array, raw_info:string}|null
 */
function bdecode_torrent(string $data): ?array
{
    $pos = 0;
    $infoSpan = null;
    $torrent = bdecode_value($data, $pos, 0, $infoSpan);
    if (!is_array($torrent) || $pos !== strlen($data) || $infoSpan === null || !is_array($torrent['info'] ?? null)) {
        return null;
    }
    return ['torrent' => $torrent, 'raw_info' => substr($data, $infoSpan[0], $infoSpan[1] - $infoSpan[0])];
}
