<?php

namespace App\Domain\Contact;

interface ContactRepositoryInterface
{
    public function findOneByPhone(string $phone): ?Contact;
    public function findById(int $id): ?Contact;
}
