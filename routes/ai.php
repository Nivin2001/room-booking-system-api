
<?php

use App\Mcp\Servers\BookingServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::web('/mcp/booking', BookingServer::class);
