<?php

/*
 * binary.class.php:
 * Utility functions for dealing with binary files/strings.
 *
 * All functions assume network byte order (big-endian).
 */

final class Binary
{
    static public function uint16($str, $pos=0)
    {
        return ord($str{$pos+0}) << 8 | ord($str{$pos+1});
    }

    static public function uint32($str, $pos=0)
    {
        $a = unpack('Nx', substr($str, $pos, 4));
        return $a['x'];
    }

    static public function nuint32($n, $str, $pos=0)
    {
        $r = array();
        for ($i = 0; $i < $n; $i++, $pos += 4)
            array_push($r, Binary::uint32($str, $pos));
        return $r;
    }

    static public function fuint32($f) { return Binary::uint32(fread($f, 4)); }
    static public function nfuint32($n, $f) { return Binary::nuint32($n, fread($f, 4*$n)); }

    static public function git_varint($str, &$pos=0)
    {
        $r = 0;
        $c = 0x80;
        for ($i = 0; $c & 0x80; $i += 7)
        {
            $c = ord($str{$pos++});
            $r |= (($c & 0x7F) << $i);
        }
        return $r;
    }
}

?>
