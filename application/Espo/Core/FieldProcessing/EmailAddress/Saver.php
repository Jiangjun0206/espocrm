<?php
/************************************************************************
 * This file is part of EspoCRM.
 *
 * EspoCRM - Open Source CRM application.
 * Copyright (C) 2014-2021 Yurii Kuznietsov, Taras Machyshyn, Oleksii Avramenko
 * Website: https://www.espocrm.com
 *
 * EspoCRM is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * EspoCRM is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with EspoCRM. If not, see http://www.gnu.org/licenses/.
 *
 * The interactive user interfaces in modified source and object code versions
 * of this program must display Appropriate Legal Notices, as required under
 * Section 5 of the GNU General Public License version 3.
 *
 * In accordance with Section 7(b) of the GNU General Public License version 3,
 * these Appropriate Legal Notices must retain the display of the "EspoCRM" word.
 ************************************************************************/

namespace Espo\Core\FieldProcessing\EmailAddress;

use Espo\Entities\EmailAddress;

use Espo\Core\{
    ORM\Entity,
    ORM\EntityManager,
    ApplicationState,
    FieldProcessing\Saver as SaverInterface,
    FieldProcessing\SaverParams,
};

class Saver implements SaverInterface
{
    private $entityManager;

    private $applicationState;

    private $accessChecker;

    public function __construct(
        EntityManager $entityManager,
        ApplicationState $applicationState,
        AccessChecker $accessChecker
    ) {
        $this->entityManager = $entityManager;
        $this->applicationState = $applicationState;
        $this->accessChecker = $accessChecker;
    }

    public function process(Entity $entity, SaverParams $params): void
    {
        $entityType = $entity->getEntityType();

        $defs = $this->entityManager->getDefs()->getEntity($entityType);

        if (!$defs->hasField('emailAddress')) {
            return;
        }

        if ($defs->getField('emailAddress')->getType() !== 'email') {
            return;
        }

        $emailAddressData = null;

        if ($entity->has('emailAddressData')) {
            $emailAddressData = $entity->get('emailAddressData');
        }

        if ($emailAddressData !== null) {
            $this->storeData($entity);

            return;
        }

        if ($entity->has('emailAddress')) {
            $this->storePrimary($entity);

            return;
        }
    }

    private function storeData(Entity $entity): void
    {
        $emailAddressValue = $entity->get('emailAddress');

        if (is_string($emailAddressValue)) {
            $emailAddressValue = trim($emailAddressValue);
        }

        $emailAddressData = null;

        if ($entity->has('emailAddressData')) {
            $emailAddressData = $entity->get('emailAddressData');
        }

        if (is_null($emailAddressData)) {
            return;
        }

        if (!is_array($emailAddressData)) {
            return;
        }

        $keyList = [];
        $keyPreviousList = [];

        $previousEmailAddressData = [];

        if (!$entity->isNew()) {
            $previousEmailAddressData = $this->entityManager
                ->getRepository('EmailAddress')
                ->getEmailAddressData($entity);
        }

        $hash = (object) [];
        $hashPrevious = (object) [];

        foreach ($emailAddressData as $row) {
            $key = trim($row->emailAddress);

            if (empty($key)) {
                continue;
            }

            $key = strtolower($key);

            $hash->$key = [
                'primary' => !empty($row->primary) ? true : false,
                'optOut' => !empty($row->optOut) ? true : false,
                'invalid' => !empty($row->invalid) ? true : false,
                'emailAddress' => trim($row->emailAddress),
            ];

            $keyList[] = $key;
        }

        if (
            $entity->has('emailAddressIsOptedOut')
            &&
            (
                $entity->isNew()
                ||
                (
                    $entity->hasFetched('emailAddressIsOptedOut')
                    &&
                    $entity->get('emailAddressIsOptedOut') !== $entity->getFetched('emailAddressIsOptedOut')
                )
            )
        ) {
            if ($emailAddressValue) {
                $key = strtolower($emailAddressValue);

                if ($key && isset($hash->$key)) {
                    $hash->{$key}['optOut'] = $entity->get('emailAddressIsOptedOut');
                }
            }
        }

        foreach ($previousEmailAddressData as $row) {
            $key = $row->lower;

            if (empty($key)) {
                continue;
            }

            $hashPrevious->$key = [
                'primary' => $row->primary ? true : false,
                'optOut' => $row->optOut ? true : false,
                'invalid' => $row->invalid ? true : false,
                'emailAddress' => $row->emailAddress,
            ];

            $keyPreviousList[] = $key;
        }

        $primary = false;

        $toCreateList = [];
        $toUpdateList = [];
        $toRemoveList = [];

        $revertData = [];

        foreach ($keyList as $key) {
            $data = $hash->$key;

            $new = true;
            $changed = false;

            if ($hash->{$key}['primary']) {
                $primary = $key;
            }

            if (property_exists($hashPrevious, $key)) {
                $new = false;

                $changed =
                    $hash->{$key}['optOut'] != $hashPrevious->{$key}['optOut'] ||
                    $hash->{$key}['invalid'] != $hashPrevious->{$key}['invalid'] ||
                    $hash->{$key}['emailAddress'] !== $hashPrevious->{$key}['emailAddress'];

                if ($hash->{$key}['primary']) {
                    if ($hash->{$key}['primary'] == $hashPrevious->{$key}['primary']) {
                        $primary = false;
                    }
                }
            }

            if ($new) {
                $toCreateList[] = $key;
            }

            if ($changed) {
                $toUpdateList[] = $key;
            }
        }

        foreach ($keyPreviousList as $key) {
            if (!property_exists($hash, $key)) {
                $toRemoveList[] = $key;
            }
        }

        foreach ($toRemoveList as $address) {
            $emailAddress = $this->getByAddress($address);

            if (!$emailAddress) {
                continue;
            }

            $delete = $this->entityManager
                ->getQueryBuilder()
                ->delete()
                ->from('EntityEmailAddress')
                ->where([
                    'entityId' => $entity->getId(),
                    'entityType' => $entity->getEntityType(),
                    'emailAddressId' => $emailAddress->getId(),
                ])
                ->build();

            $this->entityManager->getQueryExecutor()->execute($delete);
        }

        foreach ($toUpdateList as $address) {
            $emailAddress = $this->getByAddress($address);

            if ($emailAddress) {
                $skipSave = $this->checkChangeIsForbidden($emailAddress, $entity);

                if (!$skipSave) {
                    $emailAddress->set([
                        'optOut' => $hash->{$address}['optOut'],
                        'invalid' => $hash->{$address}['invalid'],
                        'name' => $hash->{$address}['emailAddress']
                    ]);

                    $this->entityManager->saveEntity($emailAddress);
                }
                else {
                    $revertData[$address] = [
                        'optOut' => $emailAddress->get('optOut'),
                        'invalid' => $emailAddress->get('invalid'),
                    ];
                }
            }
        }

        foreach ($toCreateList as $address) {
            $emailAddress = $this->getByAddress($address);

            if (!$emailAddress) {
                $emailAddress = $this->entityManager->getEntity('EmailAddress');

                $emailAddress->set([
                    'name' => $hash->{$address}['emailAddress'],
                    'optOut' => $hash->{$address}['optOut'],
                    'invalid' => $hash->{$address}['invalid'],
                ]);

                $this->entityManager->saveEntity($emailAddress);
            }
            else {
                $skipSave = $this->checkChangeIsForbidden($emailAddress, $entity);

                if (!$skipSave) {
                    if (
                        $emailAddress->get('optOut') != $hash->{$address}['optOut'] ||
                        $emailAddress->get('invalid') != $hash->{$address}['invalid'] ||
                        $emailAddress->get('emailAddress') != $hash->{$address}['emailAddress']
                    ) {
                        $emailAddress->set([
                            'optOut' => $hash->{$address}['optOut'],
                            'invalid' => $hash->{$address}['invalid'],
                            'name' => $hash->{$address}['emailAddress']
                        ]);

                        $this->entityManager->saveEntity($emailAddress);
                    }
                }
                else {
                    $revertData[$address] = [
                        'optOut' => $emailAddress->get('optOut'),
                        'invalid' => $emailAddress->get('invalid')
                    ];
                }
            }

            $entityEmailAddress = $this->entityManager->getEntity('EntityEmailAddress');

            $entityEmailAddress->set([
                'entityId' => $entity->getId(),
                'entityType' => $entity->getEntityType(),
                'emailAddressId' => $emailAddress->getId(),
                'primary' => $address === $primary,
                'deleted' => false,
            ]);

            $this->entityManager
                ->getMapper('RDB')
                ->insertOnDuplicateUpdate($entityEmailAddress, [
                    'primary',
                    'deleted',
                ]);
        }

        if ($primary) {
            $emailAddress = $this->getByAddress($primary);

            if ($emailAddress) {
                $update1 = $this->entityManager
                    ->getQueryBuilder()
                    ->update()
                    ->in('EntityEmailAddress')
                    ->set(['primary' => false])
                    ->where([
                        'entityId' => $entity->getId(),
                        'entityType' => $entity->getEntityType(),
                        'primary' => true,
                        'deleted' => false,
                    ])
                    ->build();

                $this->entityManager->getQueryExecutor()->execute($update1);

                $update2 = $this->entityManager
                    ->getQueryBuilder()
                    ->update()
                    ->in('EntityEmailAddress')
                    ->set(['primary' => true])
                    ->where([
                        'entityId' => $entity->getId(),
                        'entityType' => $entity->getEntityType(),
                        'emailAddressId' => $emailAddress->getId(),
                        'deleted' => false,
                    ])
                    ->build();

                $this->entityManager->getQueryExecutor()->execute($update2);
            }
        }

        if (!empty($revertData)) {
            foreach ($emailAddressData as $row) {
                if (empty($revertData[$row->emailAddress])) {
                    continue;
                }

                $row->optOut = $revertData[$row->emailAddress]['optOut'];
                $row->invalid = $revertData[$row->emailAddress]['invalid'];
            }

            $entity->set('emailAddressData', $emailAddressData);
        }
    }

    private function storePrimary(Entity $entity): void
    {
        if (!$entity->has('emailAddress')) {
            return;
        }

        $emailAddressValue = $entity->get('emailAddress');

        if (is_string($emailAddressValue)) {
            $emailAddressValue = trim($emailAddressValue);
        }

        $entityRepository = $this->entityManager->getRepository($entity->getEntityType());

        if (!empty($emailAddressValue)) {
            if ($emailAddressValue != $entity->getFetched('emailAddress')) {
                $emailAddressNew = $this->entityManager
                    ->getRDBRepository('EmailAddress')
                    ->where([
                        'lower' => strtolower($emailAddressValue),
                    ])
                    ->findOne();

                $isNewEmailAddress = false;

                if (!$emailAddressNew) {
                    $emailAddressNew = $this->entityManager->getEntity('EmailAddress');

                    $emailAddressNew->set('name', $emailAddressValue);

                    if ($entity->has('emailAddressIsOptedOut')) {
                        $emailAddressNew->set('optOut', (bool) $entity->get('emailAddressIsOptedOut'));
                    }

                    $this->entityManager->saveEntity($emailAddressNew);

                    $isNewEmailAddress = true;
                }

                $emailAddressValueOld = $entity->getFetched('emailAddress');

                if (!empty($emailAddressValueOld)) {
                    $emailAddressOld = $this->getByAddress($emailAddressValueOld);

                    if ($emailAddressOld) {
                        $entityRepository->unrelate($entity, 'emailAddresses', $emailAddressOld);
                    }
                }

                $entityRepository->relate($entity, 'emailAddresses', $emailAddressNew);

                if ($entity->has('emailAddressIsOptedOut')) {
                    $this->markAddressOptedOut($emailAddressValue, (bool) $entity->get('emailAddressIsOptedOut'));
                }

                $update = $this->entityManager->getQueryBuilder()
                    ->update()
                    ->in('EntityEmailAddress')
                    ->set(['primary' => true])
                    ->where([
                        'entityId' => $entity->id,
                        'entityType' => $entity->getEntityType(),
                        'emailAddressId' => $emailAddressNew->id,
                    ])
                    ->build();

                $this->entityManager->getQueryExecutor()->execute($update);

            } else {
                if (
                    $entity->has('emailAddressIsOptedOut')
                    &&
                    (
                        $entity->isNew()
                        ||
                        (
                            $entity->hasFetched('emailAddressIsOptedOut')
                            &&
                            $entity->get('emailAddressIsOptedOut') !== $entity->getFetched('emailAddressIsOptedOut')
                        )
                    )
                ) {
                    $this->markAddressOptedOut($emailAddressValue, (bool) $entity->get('emailAddressIsOptedOut'));
                }
            }

            return;
        }

        $emailAddressValueOld = $entity->getFetched('emailAddress');

        if (!empty($emailAddressValueOld)) {
            $emailAddressOld = $this->getByAddress($emailAddressValueOld);

            if ($emailAddressOld) {
                $entityRepository->unrelate($entity, 'emailAddresses', $emailAddressOld);
            }
        }
    }

    private function getByAddress(string $address): ?EmailAddress
    {
        return $this->entityManager
            ->getRepository('EmailAddress')
            ->getByAddress($address);
    }

    private function markAddressOptedOut(string $address, bool $isOptedOut = true): void
    {
        $this->entityManager
            ->getRepository('EmailAddress')
            ->markAddressOptedOut($address, $isOptedOut);
    }

    private function checkChangeIsForbidden(EmailAddress $emailAddress, Entity $entity): bool
    {
        if (!$this->applicationState->hasUser()) {
            return true;
        }

        $user = $this->applicationState->getUser();

        // @todo Check if not modifed by system.

        return !$this->accessChecker->checkEdit($user, $emailAddress, $entity);
    }
}