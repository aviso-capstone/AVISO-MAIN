<?php

namespace App\Services;

use App\Models\EmergencyContact;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class EmergencyContactService
{
    public function getForRider(User $rider): Collection
    {
        return $rider->emergencyContacts()
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public function create(User $rider, array $data): EmergencyContact
    {
        return $rider->emergencyContacts()->create($data);
    }

    public function update(EmergencyContact $contact, array $data): EmergencyContact
    {
        $contact->update($data);

        return $contact->fresh();
    }

    public function delete(EmergencyContact $contact): void
    {
        $contact->delete();
    }
}
