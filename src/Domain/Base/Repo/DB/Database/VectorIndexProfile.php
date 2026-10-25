<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Database;

/**
 * D6 (dual-dialect architecture plan `agentic-plans/backend/2026-10-24-ddd-dual-dialect-architecture.md` §3, D6):
 * engine-neutral intent for a `TYPE_VECTOR` index — never an algorithm name, the dialect maps it. No MariaDB
 * effect: both profiles render MariaDB's own `VECTOR INDEX` unchanged.
 *
 * PostgreSQL mapping (verified on pg-sandbox 2026-10-24: pgvector 0.8.6, pgvectorscale 0.9.1):
 * - `ON_DISK` (default): pgvectorscale `diskann (col vector_<metric>_ops)` at any dimension.
 * - `IN_MEMORY`: pgvector `hnsw`; `col vector_<metric>_ops` up to 2000 dims, `(col::halfvec(n)) halfvec_<metric>_ops`
 *   for 2001–4000 dims (the query renderer casts the same way in `ORDER BY` so the index serves it), generator
 *   error above 4000 dims. `halfvec` + `diskann` is not buildable and is refused by the generator.
 */
enum VectorIndexProfile: string
{
    case IN_MEMORY = 'IN_MEMORY';
    case ON_DISK = 'ON_DISK';
}
