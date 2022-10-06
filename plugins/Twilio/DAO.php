<?php
/**
 * Twilio Plugin for phplist.
 *
 * This file is a part of Twilio Plugin.
 *
 * This plugin is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 * This plugin is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * @category  phplist
 *
 * @author    Duncan Cameron
 * @copyright 2022 Duncan Cameron
 * @license   http://www.gnu.org/licenses/gpl.html GNU General Public License, Version 3
 */

namespace phpList\plugin\Twilio;

use phpList\plugin\Common\DAO as CommonDAO;
use phpList\plugin\Common\DAO\MessageTrait;
use phpList\plugin\Common\DAO\UserTrait;
use phpList\plugin\Common\DB;

class DAO extends CommonDAO
{
    use MessageTrait;
    use UserTrait;

    public function __construct()
    {
        parent::__construct(new DB());
    }
}
