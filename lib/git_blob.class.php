<?php
/*
 * Copyright (C) 2008 Patrik Fimml
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

require_once('git_object.class.php');

class GitBlob extends GitObject
{
    /**
     * @brief The data contained in this blob.
     */
    public $data = NULL;

    public function __construct($repo)
    {
	parent::__construct($repo, Git::OBJ_BLOB);
    }

    public function _unserialize($data)
    {
	$this->data = $data;
    }

    public function _serialize()
    {
	return $this->data;
    }
}

