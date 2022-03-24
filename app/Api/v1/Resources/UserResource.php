<?php

namespace App\Api\v1\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Auth;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        return [
            'id'    => $this->when($request->user(), $this->id),
            'name'  => $this->name,
            'email' => $this->when($request->user(), $this->email),
        ];
    }
}