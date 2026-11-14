<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Service;

use RuntimeException;

/**
 * A section names an image the current backend user may not read.
 */
final class ImageNotReadableException extends RuntimeException {}
