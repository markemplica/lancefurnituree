<?php

namespace App\Http\Controllers;

use App\Http\Requests\PreferenceUpdateRequest;
use App\Models\FurnitureProduct;
use Illuminate\Http\Request;

class PreferenceController extends Controller
{
    public function edit(Request $request)
    {
        \App\Models\RoomImage::recoverStalledForUser($request->user()->id);
        $preference = $request->user()->preference()->first();
        $preferenceComplete = filled($preference?->furniture_type);
        $options = FurnitureProduct::catalogFilterOptions();
        $styles = $options['styles'];
        $woodTypes = $options['woods'];
        $varnishFinishes = $options['finishes'];
        $furnitureTypes = $options['categories'];
        $rooms = $request->user()->roomImages()->latest()->orderByDesc('id')->paginate(6, ['*'], 'photos_page')->withQueryString();
        $selectedRoomId = $request->user()->roomImages()->whereKey($request->integer('room'))->exists() ? $request->integer('room') : null;

        return view('preferences.edit', compact('preference', 'preferenceComplete', 'styles', 'woodTypes', 'varnishFinishes', 'furnitureTypes', 'rooms', 'selectedRoomId'));
    }

    public function update(PreferenceUpdateRequest $request)
    {
        $data = $request->validated();
        unset($data['budget_range']);

        $preference = $request->user()->preference()->updateOrCreate([], $data);

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Preferences updated successfully.',
                'preference' => $preference->only([
                    'furniture_type',
                    'budget_min',
                    'budget_max',
                    'style',
                    'wood_type',
                    'varnish_finish',
                    'room_width_m',
                    'room_depth_m',
                    'notes',
                ]),
            ]);
        }

        return redirect()
            ->to(route('preferences.edit').'#room-preview')
            ->with('success', 'Preferences saved. Generate recommendations or add an optional room photo.');
    }
}
