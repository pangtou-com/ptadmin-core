<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Runtime;

enum EventMode: string
{
    case SyncAfterCommit = 'sync_after_commit';
    case Queued = 'queued';
}
