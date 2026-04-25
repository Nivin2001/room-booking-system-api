<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SpaceResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
     public function toArray($request)
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'location' => $this->location,
            'price' => $this->price_per_hour,
            'capacity' => $this->capacity,
            'status' => $this->status,
            'categories' => CategoryResource::collection($this->whenLoaded('categories')),
        ];
    }
}
