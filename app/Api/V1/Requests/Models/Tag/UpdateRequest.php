<?php

/**
 * TagUpdateRequest.php
 * Copyright (c) 2019 james@firefly-iii.org
 *
 * This file is part of Firefly III (https://github.com/firefly-iii).
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace FireflyIII\Api\V1\Requests\Models\Tag;

use FireflyIII\Models\Location;
use FireflyIII\Support\Request\AppendsLocationData;
use FireflyIII\Support\Request\ChecksLogin;
use FireflyIII\Support\Request\ConvertsDataTypes;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Class UpdateRequest
 *
 * @codeCoverageIgnore
 */
class UpdateRequest extends FormRequest
{
    use ConvertsDataTypes, ChecksLogin, AppendsLocationData;


    /**
     * Get all data from the request.
     *
     * @return array
     */
    public function getAll(): array
    {
        // This is the way.
        $fields = [
            'tag'         => ['tag', 'string'],
            'date'        => ['date', 'date'],
            'description' => ['description', 'string'],
        ];
        $data   = $this->getAllData($fields);
        $data   = $this->appendLocationData($data, null);

        return $data;
    }

    /**
     * The rules that the incoming request must be matched against.
     *
     * @return array
     */
    public function rules(): array
    {
        $tag = $this->route()->parameter('tagOrId');
        // TODO is uniqueObjectForUser not obsolete?
        $rules = [
            'tag'         => 'min:1|uniqueObjectForUser:tags,tag,' . $tag->id,
            'description' => 'min:1|nullable',
            'date'        => 'date|nullable',
        ];

        return Location::requestRules($rules);
    }
}
