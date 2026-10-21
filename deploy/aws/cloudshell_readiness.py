#!/usr/bin/env python3
"""Check explicit recovery inputs; AWS reads happen only with --check-aws."""
import argparse
import shutil
import subprocess
import sys
from pathlib import Path

from install_cutover import Aws, GuardError, ROOT, artifact_evidence, load_state, preflight, read_json, require, validate_config, write_json


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--config", required=True)
    parser.add_argument("--out", required=True, help="New private report path outside the application source tree.")
    parser.add_argument("--check-aws", action="store_true", help="Explicitly permit read-only STS, EC2, ACM, RDS, IAM, Secrets Manager metadata and Beanstalk checks.")
    args = parser.parse_args(argv)
    try:
        config = validate_config(read_json(args.config))
        evidence = artifact_evidence(config)
        state = load_state(config)
        report = {"account_id": config["account_id"], "region": config["region"], "environment": config["environment"],
                  "offline_inputs_valid": True, "aws_cli_available": shutil.which("aws") is not None,
                  "media_objects": len(evidence["media"]), "media_bytes": sum(item["bytes"] for item in evidence["media"]),
                  "completed_phases": list(state["completed"]), "aws_checked": args.check_aws,
                  "cloudshell_notes": ["CloudShell VPC homes are ephemeral: preserve the private journal and backup inventory in a controlled durable location after each phase.",
                                       "RDS is private. SQL restore/migrations need a restricted host in the intended VPC; ordinary CloudShell is not assumed to reach it.",
                                       "CloudShell VPC internet access requires the actual subnet/NAT design. A public subnet with an internet gateway alone does not give CloudShell a public address.",
                                       "This report does not attest a live restore, DNS ownership, working ingress, costs, provider consent or successful delivery."]}
        if args.check_aws:
            report["aws"] = preflight(config, Aws(config))
        output = Path(args.out).absolute()
        require(not output.resolve().is_relative_to(ROOT), "Save the readiness report outside the application source tree.")
        write_json(output, report)
        print("Saved " + str(output))
        print("Read-only AWS preflight completed." if args.check_aws else "Offline check completed. No AWS request was made.")
        return 0
    except (GuardError, OSError, subprocess.TimeoutExpired, KeyError, TypeError, ValueError, AttributeError) as error:
        print("Stopped: " + (str(error) if isinstance(error, GuardError) else "A local file could not be read; details suppressed."), file=sys.stderr)
        return 2


if __name__ == "__main__":
    sys.exit(main())
