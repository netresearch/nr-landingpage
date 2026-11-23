<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Service;

use RuntimeException;

/**
 * The child records of a section cannot be written as they are: a value, a
 * grant or the page TSconfig refuses them. Thrown before anything is written,
 * so the editor gets the reason instead of an element without its items.
 */
final class SectionChildrenRefusedException extends RuntimeException {}
