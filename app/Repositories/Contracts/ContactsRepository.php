<?php

namespace App\Repositories\Contracts;

use App\Models\ContactGroups;

/**
 * Interface ContactsRepository.
 */
interface ContactsRepository extends BaseRepository
{
    /**
     * @return mixed
     */
    public function store(array $input);

    /**
     * @return mixed
     */
    public function update(ContactGroups $contactGroups, array $input);

    /**
     * @return mixed
     */
    public function destroy(ContactGroups $contactGroups);

    /**
     * @return mixed
     */
    public function batchDestroy(array $ids);

    /**
     * @return mixed
     */
    public function batchActive(array $ids);

    /**
     * @return mixed
     */
    public function batchDisable(array $ids);

    /**
     * @return mixed
     */
    public function storeContact(ContactGroups $contactGroups, array $input);

    /**
     * @return mixed
     */
    public function updateContactStatus(ContactGroups $contactGroups, array $input);

    /**
     * @return mixed
     */
    public function updateContact(ContactGroups $contactGroups, array $input);

    /**
     * delete single contact
     *
     *
     * @return mixed
     */
    public function contactDestroy(ContactGroups $contactGroups, string $id);

    /**
     * @return mixed
     */
    public function batchContactDestroy(ContactGroups $contactGroups, array $ids);

    /**
     * @return mixed
     */
    public function batchContactSubscribe(ContactGroups $contactGroups, array $ids);

    /**
     * @return mixed
     */
    public function batchContactUnsubscribe(ContactGroups $contactGroups, array $ids);

    /**
     * @return mixed
     */
    public function batchContactCopy(ContactGroups $contactGroups, array $input);

    /**
     * @return mixed
     */
    public function batchContactMove(ContactGroups $contactGroups, array $input);

    public function updateOrCreateFieldsFromRequest(ContactGroups $contactGroups, array $input);
}
