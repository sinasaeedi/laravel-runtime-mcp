<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Queue;

use Illuminate\Queue\SyncQueue;

/** A package driver built on SyncQueue under another driver name: its size() is SyncQueue's 0. */
final class ImmediateQueue extends SyncQueue {}
