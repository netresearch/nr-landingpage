<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

namespace Netresearch\NrLandingpage\Service;

use RuntimeException;

/**
 * A template names content types, and the target page's TSconfig and the
 * editor's permissions leave none of them. Generating anyway would ask the
 * model for any content type, which an empty list means to the generator.
 */
final class NoContentTypeOfferedException extends RuntimeException {}
