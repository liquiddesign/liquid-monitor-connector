//! `lqcron` — lokální plánovač cronů connectoru LQDeck v4.
//!
//! Specifikace: `docs/v4-local-scheduler.md` (plán) a `docs/v4-contract.md` (kontrakt).

mod cli;
mod commands;
mod cronexpr;
mod db;
mod defs;
mod deliver;
mod export;
mod heartbeat;
mod model;
mod outbox;
mod pause;
mod planner;
mod queue;
mod retention;
mod runjob;
mod select;
mod services;
mod sync;
mod tick;
mod util;

use std::time::Duration;

use cli::{Ctx, Opts};
use util::{Res, VERSION, fail};

const USAGE: &str = "lqcron — lokální plánovač cronů (connector v4)

Použití: lqcron <příkaz> [volby]

  tick [--php=…] [--move-state]       jeden průchod plánovače (systémový cron každou minutu)
  run <code> [--args=json] [--sync]   zařadit job; --sync ho spustí hned v popředí
  enqueue <code> [--args=json] [--delay=s]
  cancel <run_uuid>                   ukončit běh (cancelled, bez opakování)
  cron disable|enable <code>          lokální vypnutí/zapnutí cronu
  plan [--hours=24] [--json]          co a kdy poběží
  status [--json]                     tick, fronta, běhy, outbox, pauza, služby
  logs <code|run_uuid> [--tail]       log běhu
  pause [--drain[=sec]] [--ttl=sec]   deploy pauza (TTL default 1800 s, --ttl=0 bez TTL)
  resume [--all]                      zrušit deploy pauzu (--all i vzdálenou)
  wait-slot <path> [--timeout=sec]    počkat, až ze slotu nic neběží
  sync [--php=…]                      znovu načíst konfiguraci a definice (PHP export)
  deliver                             odeslat outbox a stáhnout příkazy (spouští tick)
  flush [--timeout=sec]               totéž v popředí, dokud není outbox prázdný
  outbox retry-rejected               znovu odeslat trvale odmítnuté události
  verify [binary]                     ověřit binárku proti .sha256
  --version

Mimo tick se stateDir hledá v ./temp/lqcron/export.json (nebo --state-dir=…).
";

fn version() -> String {
    format!(
        "lqcron {VERSION} (schema {}, min reader {})",
        db::SCHEMA_VERSION,
        db::MIN_READER_VERSION
    )
}

fn run(args: Vec<String>) -> Res<i32> {
    let Some(cmd) = args.first().cloned() else {
        print!("{USAGE}");
        return Ok(2);
    };
    let o = Opts::parse(&args[1..]);
    match cmd.as_str() {
        "--version" | "-V" | "version" => {
            println!("{}", version());
            Ok(0)
        }
        "--help" | "-h" | "help" => {
            print!("{USAGE}");
            Ok(0)
        }
        "tick" => tick::tick(&tick::TickArgs {
            php: o.value("php").map(str::to_string),
            move_state: o.flag("move-state"),
        }),
        "run-job" => {
            let uuid = o
                .positional
                .first()
                .ok_or_else(|| fail("usage: lqcron run-job <run_uuid>"))?;
            util::set_umask();
            let ctx = Ctx::resolve(&o)?;
            let env = ctx.env(o.value("php"));
            runjob::run_job(&env, uuid, o.flag("adopt"))
        }
        "sync" => sync::sync(&sync::SyncArgs {
            php: o.value("php").map(str::to_string),
            background: o.flag("background"),
        }),
        "deliver" | "flush" => {
            let ctx = Ctx::resolve(&o)?;
            let export = ctx
                .export
                .as_ref()
                .ok_or_else(|| fail("deliver needs temp/lqcron/export.json (url, api_key)"))?;
            deliver::deliver(
                &ctx.state_dir,
                export,
                &deliver::DeliverArgs {
                    flush: cmd == "flush",
                    timeout: o
                        .int("timeout")?
                        .map(|s| Duration::from_secs(s.max(0) as u64)),
                },
            )
        }
        "verify" => cli::verify(&o),
        other => {
            util::set_umask();
            let ctx = Ctx::resolve(&o)?;
            match other {
                "status" => cli::status(&ctx, &o),
                "plan" => cli::plan(&ctx, &o),
                "logs" => cli::logs(&ctx, &o),
                "pause" => cli::pause_cmd(&ctx, &o),
                "resume" => cli::resume_cmd(&ctx, &o),
                "wait-slot" => cli::wait_slot(&ctx, &o),
                "cancel" => cli::cancel_cmd(&ctx, &o),
                "enqueue" => cli::enqueue_cmd(&ctx, &o),
                "run" => cli::run_cmd(&ctx, &o),
                "cron" => cli::cron_cmd(&ctx, &o),
                "outbox" => cli::outbox_cmd(&ctx, &o),
                _ => {
                    eprint!("lqcron: unknown command {other:?}\n\n{USAGE}");
                    Ok(2)
                }
            }
        }
    }
}

fn main() {
    let args: Vec<String> = std::env::args().skip(1).collect();
    let code = match run(args) {
        Ok(c) => c,
        Err(e) => {
            eprintln!("lqcron: {e}");
            1
        }
    };
    std::process::exit(code);
}
