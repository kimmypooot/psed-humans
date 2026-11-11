<?php

namespace App\Support;

use App\Models\User;

/** The sidebar for a signed-in user (shape: see SidebarNav.vue). */
class Navigation
{
    /** @return list<array<string, mixed>> */
    public static function for(User $user): array
    {
        return [
            ['label' => 'Dashboard', 'href' => '/dashboard', 'icon' => 'home'],
            ['label' => 'Notifications', 'href' => '/notifications/all', 'icon' => 'bell'],
            ['heading' => 'HR Mechanisms'],
            ['label' => 'All Mechanisms', 'href' => '/mechanisms', 'icon' => 'clipboard'],
            ['heading' => 'Insights'],
            ['label' => 'Analytics', 'href' => '/analytics', 'icon' => 'chartBar'],
            ['label' => 'Reports', 'href' => '/reports', 'icon' => 'documentText'],
        ];
    }
}
