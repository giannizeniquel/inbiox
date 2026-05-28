<?php

namespace App\Domain\Tag;

interface TagRepositoryInterface
{
    public function findById(int $id): ?Tag;
    public function findByName(string $name): ?Tag;
    public function findAll(): array;
}