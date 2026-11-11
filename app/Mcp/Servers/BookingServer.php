<?php
namespace App\Mcp\Servers;

use App\Mcp\Tools\SearchAvailableSpacesTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Booking Server')]
#[Version('0.0.1')]
#[Instructions('Helps users search for spaces by location, capacity, and hourly price. Search results do not guarantee booking availability.')]
class BookingServer extends Server
{
    protected array $tools = [
        SearchAvailableSpacesTool::class,
    ];

    protected array $resources = [
        //
    ];

    protected array $prompts = [
        //
    ];
}
