# Release prerequisite: Symfony Filesystem security patch

The 4 November 2026 opportunity-release application check detected advisory GHSA-6prc-h956-hxjq (PKSA-g499-cm4q-v35y) in the previously locked symfony/filesystem v7.4.21. Security auditing remains enabled; no exception or ignore was added.

A minimal Composer resolution updated only symfony/filesystem to v7.4.23, source reference 53d609da66578dad5a39fe290e8f924ec8f05e93. All other package records and composer.json/content-hash stayed unchanged. Composer also refreshed its lockfile plugin API metadata from 2.9 to 2.11. The generated lockfile SHA-256 is 49be8fc67965ab383e5ec7efc581cd39aae1b50d921c7642f4a6a6a184e10d92.

Preparation run 41899964576 passed composer validate --strict and composer audit --locked --no-interaction, reporting no security advisories. Its exact package delta was reviewed. The temporary preparation workflow is not part of the final release; it created only an unreferenced Git blob and evidence artifact, not a branch update or production change.

Full application, native publication rehearsal, browser and production checks remain mandatory on the committed lockfile.

Upstream evidence: https://symfony.com/blog/ghsa-6prc-h956-hxjq-filesystem-path-treats-any-prefix-before-as-a-root-letting-paths-escape-their-base-directory and https://symfony.com/blog/symfony-7-4-23-released
