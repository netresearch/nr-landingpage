<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Service\Workspace;

use RuntimeException;

/**
 * A workspace action the rules refuse. The message is safe to show to the
 * backend user who asked for it.
 */
final class WorkspaceRefusedException extends RuntimeException {}
