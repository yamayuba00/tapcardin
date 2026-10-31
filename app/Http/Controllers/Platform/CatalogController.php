<?php

namespace App\Http\Controllers\Platform;

use App\Models\ContactType;
use App\Models\LanyardHardware;
use App\Models\LanyardWidth;
use App\Support\Audit;
use App\Support\Contacts\ContactTypes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Katalog (platform.catalog.manage): harga lebar tali & pengait lanyard, jenis kontak bio link (aktif/label/urutan). */
class CatalogController extends PlatformController
{
    public function show(): JsonResponse
    {
        $this->can('catalog.manage');

        return $this->ok([
            'lanyard_widths' => LanyardWidth::orderBy('sort')->get()->map(fn ($w) => $w->only(['public_id', 'width_mm', 'label', 'price_single', 'price_double', 'min_qty', 'sort', 'is_active'])),
            'lanyard_hardware' => LanyardHardware::orderBy('sort')->get()->map(fn ($h) => $h->only(['public_id', 'key', 'name', 'description', 'price', 'sort', 'is_active'])),
            'contact_types' => ContactType::with('group')->orderBy('group_id')->orderBy('sort')->get()->map(fn ($t) => [
                'public_id' => $t->public_id, 'key' => $t->key, 'label' => $t->label, 'group' => $t->group->name, 'input' => $t->input,
                'icon' => $t->icon, 'sort' => $t->sort, 'is_active' => $t->is_active,
            ]),
        ]);
    }

    public function saveWidth(Request $request, ?string $id = null): JsonResponse
    {
        $this->can('catalog.manage');
        $w = $id ? LanyardWidth::where('public_id', $id)->firstOrFail() : new LanyardWidth;
        $data = $request->validate([
            'width_mm' => [$id ? 'sometimes' : 'required', 'integer', 'min:10', 'max:40', Rule::unique('lanyard_widths', 'width_mm')->ignore($w->id)],
            'label' => [$id ? 'sometimes' : 'required', 'string', 'max:60'],
            'price_single' => [$id ? 'sometimes' : 'required', 'integer', 'min:0'],
            'price_double' => [$id ? 'sometimes' : 'required', 'integer', 'min:0'],
            'min_qty' => ['sometimes', 'integer', 'min:1'],
            'sort' => ['sometimes', 'integer'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $before = $w->exists ? $w->toArray() : null;
        $w->fill($data)->save();
        Audit::platform($id ? 'catalog.lanyard_width.updated' : 'catalog.lanyard_width.created', $w, $before, $w->toArray());

        return $this->show();
    }

    public function saveHardware(Request $request, ?string $id = null): JsonResponse
    {
        $this->can('catalog.manage');
        $h = $id ? LanyardHardware::where('public_id', $id)->firstOrFail() : new LanyardHardware;
        $data = $request->validate([
            'name' => [$id ? 'sometimes' : 'required', 'string', 'max:60'],
            'description' => ['nullable', 'string', 'max:200'],
            'price' => [$id ? 'sometimes' : 'required', 'integer', 'min:0'],
            'sort' => ['sometimes', 'integer'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        if (! $id) {
            $data['key'] = Str::limit(Str::slug($data['name'], '_'), 24, '').'_'.Str::lower(Str::random(3));
        }
        $before = $h->exists ? $h->toArray() : null;
        $h->fill($data)->save();
        Audit::platform($id ? 'catalog.hardware.updated' : 'catalog.hardware.created', $h, $before, $h->toArray());

        return $this->show();
    }

    public function updateContactType(Request $request, string $id): JsonResponse
    {
        $this->can('catalog.manage');
        $t = ContactType::where('public_id', $id)->firstOrFail();
        $data = $request->validate(['label' => ['sometimes', 'string', 'max:60'], 'sort' => ['sometimes', 'integer'], 'is_active' => ['sometimes', 'boolean']]);
        $before = $t->only(['label', 'sort', 'is_active']);
        $t->update($data);
        ContactTypes::forget();
        Audit::platform('catalog.contact_type.updated', $t, $before, $t->only(['label', 'sort', 'is_active']));

        return $this->show();
    }
}
