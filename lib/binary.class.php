<?php
/*
 * binary.class.php:
 * Utility functions for dealing with binary files/strings.
 * All functions assume network byte order (big-endian).
 *
 * Copyright (C) 2008, 2009 Patrik Fimml
 *
 * This file is part of glip.
 *
 * glip is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
 * (at your option) any later version.

 * glip is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with glip.  If not, see <http://www.gnu.org/licenses/>.
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
            $r[] = Binary::uint32($str, $pos);
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

