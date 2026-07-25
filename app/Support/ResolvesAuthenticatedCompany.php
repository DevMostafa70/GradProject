<?php

namespace App\Support;

use App\Models\Company;
use App\Models\User;
use RuntimeException;

trait ResolvesAuthenticatedCompany
{
    protected function authenticatedCompany(): Company
    {
        $user = request()->user();

        if ($user instanceof Company) {
            return $user;
        }

        if ($user instanceof User && $user->isCompanyEmployee() && $user->company) {
            return $user->company;
        }

        throw new RuntimeException('Company not found or unauthorized.');
    }

    protected function reviewerIdentity(): array
    {
        $user = request()->user();

        if ($user instanceof Company) {
            return ['type' => 'company', 'id' => $user->getKey()];
        }

        if ($user instanceof User) {
            return ['type' => 'user', 'id' => $user->getKey()];
        }

        return ['type' => 'unknown', 'id' => null];
    }
}
