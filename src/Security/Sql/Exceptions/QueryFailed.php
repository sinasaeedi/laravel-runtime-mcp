<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Security\Sql\Exceptions;

/** Spec 5.2, 01-14 Part D. Any other refusal: a syntax error, stacked statements (42601, 1064), a lost connection (HY000, 2006). */
final class QueryFailed extends QueryRejected {}
