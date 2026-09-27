<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\GetScreenStatusTool;
use App\Mcp\Tools\ListScreensTool;
use App\Mcp\Tools\PublishContentTool;
use App\Mcp\Tools\ScheduleContentTool;
use App\Mcp\Tools\SummarizeUpdatesForSignageTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Signage Broadcast Server')]
#[Version('0.1.0')]
#[Instructions(
    <<<'TEXT'
    Tools for a digital signage broadcast platform.

    Important: content is one global on-air playlist shared by every screen
    in the fleet. There is no per-screen or per-location targeting yet —
    publish_content and schedule_content affect what every screen shows,
    never a single screen. Screens (get_screen_status, list_screens) only
    report their own connectivity/health; they don't have individually
    assignable content, so "what's playing on screen X" is really "what's
    on-air fleet-wide right now."
    TEXT
)]
class SignageServer extends Server
{
    protected array $tools = [
        ListScreensTool::class,
        GetScreenStatusTool::class,
        PublishContentTool::class,
        ScheduleContentTool::class,
        SummarizeUpdatesForSignageTool::class,
    ];
}
