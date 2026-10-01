-- Runs ONCE, only when the Postgres data volume is empty.
-- Tests run against a real PostgreSQL database (same engine as production),
-- kept separate from the development database so tests can wipe it freely.
CREATE DATABASE gym_testing;
