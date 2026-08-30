# Diagnostics

++PHP reports project, syntax, type, generic, checked-error, `when`, interoperability, emission, and internal compiler conditions through one stable diagnostic model. Human-facing console output and JSON output consume the same processed sequence.

## Processing

Diagnostics are validated against the catalog, sanitized, deduplicated by code, source range, identity, related labels, and content, then passed through bounded cascade suppression and a stable sorter. Compiler-owned semantic findings take precedence over a corresponding backend fallback. Distinct findings on the same line remain distinct. Producers provide condition-specific help where possible; a family-specific actionable fallback ensures every active diagnostic remains useful.

The stable sort order is severity (`Error`, `Warning`, `Note`), source presence and project-relative display path, byte range, code, identity, and message. Source offsets are zero-based bytes. Lines and columns are one-based, and columns count Unicode code points.

## Console output

Console diagnostics include a severity/code/title heading, message, project-relative source location, contextual source frame, primary underline, related source frames, and optional help. Tabs use four-column stops, long lines are clipped to terminal width, multiline spans highlight at most four source lines, and control bytes are escaped.

Diagnostics use standard error when the terminal exposes a separate error channel. Command results, build summaries, AST data, and editor protocol responses use standard output. `--ansi` forces semantic decoration, `--no-ansi` disables it, and explicit flags override the environment. Automatic decoration is disabled when `NO_COLOR` is nonempty or `TERM=dumb`.

## JSON output

`--format=json` writes exactly one versioned JSON document to standard output and leaves standard error empty. JSON is never decorated. Each diagnostic has the following stable key order:

1. `code`
2. `severity`
3. `title`
4. `message`
5. `location`
6. `related`
7. `help`
8. `debug`, only when `--debug` is active

Locations contain a forward-slash display path and half-open start/end ranges. A diagnostic without a primary source uses `null`. JSON output ends with one line feed.

## Debug details

Normal output is compiler-oriented and does not expose backend names, backend identifiers, analysis workspace paths, generated analysis paths, temporary configuration paths, or raw subprocess commands. `--debug` adds normalized JSON-safe values and an explicit origin (`compiler`, `php-parser`, `phpstan`, or `subprocess`). Debug output can contain implementation details and should be reviewed before sharing publicly.

## Catalog

The table below is generated from `DiagnosticCatalog`. Reserved codes preserve stable identities for earlier planning boundaries and cannot be emitted at runtime.

<!-- diagnostic-catalog:start -->
| Code | Family | Status | Severity | Title |
| --- | --- | --- | --- | --- |
| `P0001` | `project` | `active` | `error` | Project Configuration Not Found |
| `P0002` | `project` | `active` | `error` | Project Configuration Is Not Readable |
| `P0003` | `project` | `active` | `error` | Invalid Project Configuration JSON |
| `P0004` | `project` | `active` | `error` | Unknown Configuration Property |
| `P0005` | `project` | `active` | `error` | Missing Configuration Property |
| `P0006` | `project` | `active` | `error` | Invalid Configuration Property Type |
| `P0007` | `project` | `active` | `error` | Unsupported Target PHP Version |
| `P0008` | `project` | `active` | `error` | Unsafe Project Path |
| `P0009` | `project` | `active` | `error` | Project Configuration Already Exists |
| `P0010` | `project` | `reserved` | `error` | Compiler Frontend Is Not Available |
| `P0011` | `project` | `active` | `error` | Project Path Does Not Exist |
| `P0012` | `project` | `active` | `error` | Project Path Is Not A Directory |
| `P0013` | `project` | `active` | `error` | Configured Paths Overlap |
| `P0014` | `project` | `active` | `error` | Source Path Does Not Exist |
| `P0015` | `project` | `active` | `error` | Source Path Is Not A Directory |
| `P0016` | `project` | `active` | `error` | File Is Outside Project Root |
| `P0017` | `project` | `active` | `error` | Project Cleanup Failed |
| `P0018` | `project` | `active` | `error` | Input Path Does Not Exist |
| `P0019` | `project` | `active` | `error` | Input Path Is Not A File |
| `P0020` | `project` | `active` | `error` | Invalid Output Format |
| `P0021` | `project` | `active` | `error` | Project Initialization Failed |
| `P0022` | `project` | `active` | `error` | Invalid Invocation |
| `P0023` | `project` | `active` | `error` | Project Source Discovery Failed |
| `P0024` | `project` | `active` | `error` | Selected Path Excluded |
| `P0025` | `project` | `active` | `error` | Selected Path Not Readable |
| `P1001` | `syntax` | `active` | `error` | Invalid PHP Syntax |
| `P1002` | `syntax` | `active` | `error` | Explicit Source File Is Required |
| `P1003` | `syntax` | `reserved` | `error` | Directory Compilation Unavailable |
| `P1004` | `syntax` | `active` | `error` | Unsupported Source File |
| `P1005` | `syntax` | `active` | `error` | Selected Path Is Outside Configured Source Roots |
| `P1006` | `syntax` | `active` | `error` | Source File Not Readable |
| `P1007` | `syntax` | `reserved` | `error` | PHP Source Is Not Build Target |
| `P1008` | `syntax` | `active` | `error` | Invalid Extension Syntax |
| `P1009` | `syntax` | `active` | `error` | Unsupported Extension Syntax |
| `P1010` | `syntax` | `active` | `error` | Extension Normalization Failed |
| `P2001` | `type` | `reserved` | `error` | Typed Local Syntax Not Active |
| `P2002` | `type` | `active` | `error` | Assignment Cannot Declare Variable |
| `P2003` | `type` | `active` | `error` | Local Variable Is Not Declared |
| `P2004` | `type` | `active` | `error` | Duplicate Local Declaration |
| `P2005` | `type` | `active` | `error` | Readonly Local Cannot Be Reassigned |
| `P2006` | `type` | `active` | `error` | Readonly Local Cannot Be Mutated |
| `P2007` | `type` | `active` | `error` | Readonly Local Cannot Be Referenced |
| `P2008` | `type` | `active` | `error` | Initializer Is Not Assignable To Declared Type |
| `P2009` | `type` | `active` | `error` | Assignment Is Not Assignable To Declared Type |
| `P2010` | `type` | `active` | `error` | Unsupported Local Binding Position |
| `P2011` | `type` | `active` | `error` | Missing Parameter Type |
| `P2012` | `type` | `active` | `error` | Missing Return Type |
| `P2013` | `type` | `active` | `error` | Missing Property Type |
| `P2014` | `type` | `active` | `error` | Implicit Mixed Is Not Allowed |
| `P2015` | `type` | `active` | `error` | Argument Type Does Not Match |
| `P2016` | `type` | `active` | `error` | Return Type Does Not Match |
| `P2017` | `type` | `active` | `error` | Not All Paths Return A Value |
| `P2018` | `type` | `active` | `error` | Method Does Not Exist |
| `P2019` | `type` | `active` | `error` | Property Does Not Exist |
| `P2020` | `type` | `active` | `error` | Type Does Not Exist |
| `P2021` | `type` | `active` | `error` | Function Does Not Exist |
| `P2022` | `type` | `active` | `error` | Dynamic Property Is Not Allowed |
| `P2023` | `type` | `active` | `error` | Unsafe Dynamic Construct |
| `P2024` | `type` | `active` | `error` | Property Type Does Not Match |
| `P2025` | `type` | `active` | `error` | Null Is Not Assignable |
| `P2026` | `type` | `active` | `error` | Loop Binding Type Does Not Match |
| `P2027` | `type` | `active` | `error` | Local Variable May Be Uninitialized |
| `P2028` | `type` | `active` | `error` | Readonly Foreach Binding Not Supported |
| `P2029` | `type` | `active` | `error` | Multiple Typed For Initializers Are Not Supported |
| `P2030` | `type` | `active` | `error` | Invalid Composite Type |
| `P2031` | `type` | `active` | `error` | Intersection Type Is Not Satisfied |
| `P2032` | `type` | `reserved` | `error` | Composite Type Is Not Assignable |
| `P2033` | `type` | `active` | `error` | Strict Types Cannot Be Disabled |
| `P2034` | `type` | `active` | `error` | Duplicate Project Declaration |
| `P2099` | `type` | `active` | `error` | Static Analysis Error |
| `P3001` | `generic` | `reserved` | `error` | Generic Syntax Not Active |
| `P3002` | `generic` | `active` | `error` | Duplicate Type Parameter |
| `P3003` | `generic` | `active` | `error` | Unknown Type Parameter |
| `P3004` | `generic` | `active` | `error` | Generic Type Argument Count Does Not Match |
| `P3005` | `generic` | `active` | `error` | Type Argument Does Not Satisfy Bound |
| `P3006` | `generic` | `active` | `error` | Generic Type Arguments Are Required |
| `P3007` | `generic` | `active` | `error` | Type Is Not Generic |
| `P3008` | `generic` | `active` | `error` | Generic Runtime Operation Is Not Allowed |
| `P3009` | `generic` | `active` | `error` | Static Member Cannot Use Class Type Parameter |
| `P3010` | `generic` | `active` | `error` | Generic Documentation Conflicts With Native Syntax |
| `P3011` | `generic` | `active` | `error` | Invalid Generic Bound |
| `P3012` | `generic` | `active` | `error` | Typed Array Key Type Is Invalid |
| `P3013` | `generic` | `active` | `error` | Typed Array Value Type Does Not Match |
| `P3014` | `generic` | `active` | `error` | Typed Array Key Type Does Not Match |
| `P3015` | `generic` | `active` | `error` | Operation Would Break List Shape |
| `P3016` | `generic` | `active` | `error` | Generic Type Is Invariant |
| `P3099` | `generic` | `active` | `error` | Generic Static Analysis Error |
| `P4001` | `checked-error` | `reserved` | `error` | Throws Syntax Not Active |
| `P4002` | `checked-error` | `active` | `error` | Error Type Is Not Throwable |
| `P4003` | `checked-error` | `active` | `error` | Checked Error Is Not Handled |
| `P4004` | `checked-error` | `active` | `error` | Checked Error Declaration Is Not Covariant |
| `P4005` | `checked-error` | `active` | `warning` | Unchecked Call Boundary |
| `P4006` | `checked-error` | `active` | `error` | Native Throws Clause Is Required |
| `P4007` | `checked-error` | `active` | `error` | Throws Documentation Conflicts With Native Clause |
| `P4008` | `checked-error` | `active` | `error` | Checked Error Cannot Escape File Scope |
| `P4009` | `checked-error` | `active` | `error` | Checked Error Cannot Escape Anonymous Callable |
| `P4010` | `checked-error` | `active` | `error` | Checked Error Cannot Escape Destructor |
| `P4011` | `checked-error` | `active` | `error` | Duplicate Error Declaration |
| `P4012` | `checked-error` | `active` | `error` | Caught Error Is Never Thrown |
| `P4013` | `checked-error` | `active` | `error` | Error Catch Is Unreachable |
| `P5001` | `when` | `reserved` | `error` | When Syntax Not Active |
| `P5002` | `when` | `active` | `error` | When Branch Does Not Produce A Value |
| `P5003` | `when` | `active` | `error` | When Result Requires A Value |
| `P5004` | `when` | `active` | `error` | When Result Type Does Not Match |
| `P5005` | `when` | `active` | `error` | When Position Is Not Supported |
| `P5006` | `when` | `active` | `error` | When Control Transfer Is Not Allowed |
| `P5007` | `when` | `active` | `error` | When Yield Is Not Allowed |
| `P5008` | `when` | `active` | `error` | When Goto Is Not Allowed |
| `P5009` | `when` | `active` | `error` | When By-Reference Argument Is Not Allowed |
| `P5010` | `when` | `active` | `error` | When Branch Could Not Be Parsed |
| `P6001` | `interop` | `active` | `error` | Invalid Composer Configuration |
| `P6002` | `interop` | `active` | `error` | Invalid Composer Autoload Mapping |
| `P6003` | `interop` | `active` | `error` | Invalid Installed Composer Metadata |
| `P6004` | `interop` | `active` | `error` | Configured Stub Path Is Invalid |
| `P6005` | `interop` | `active` | `error` | Static Analysis Failed |
| `P6006` | `interop` | `active` | `error` | Static Analysis Result Is Invalid |
| `P6007` | `interop` | `active` | `error` | Analysis Workspace Could Not Be Prepared |
| `P6008` | `interop` | `active` | `warning` | Composer Autoload Does Not Target Build Output |
| `P6009` | `interop` | `active` | `error` | Composer Autoload Mapping Cannot Be Projected |
| `P6010` | `interop` | `active` | `error` | Composer Configuration Could Not Be Updated |
| `P6011` | `interop` | `active` | `error` | Composer Runtime Mapping Conflicts With Build Output |
| `P7001` | `emission` | `reserved` | `error` | Generated PHP Could Not Be Written |
| `P7002` | `emission` | `active` | `error` | Generated PHP Output Path Collision |
| `P7003` | `emission` | `active` | `error` | Generated PHP Is Invalid |
| `P7004` | `emission` | `active` | `error` | Build Manifest Is Invalid |
| `P7005` | `emission` | `active` | `error` | Build Could Not Be Staged |
| `P7006` | `emission` | `active` | `error` | Build Could Not Be Committed |
| `P7007` | `emission` | `active` | `error` | Previous Build Could Not Be Restored |
| `P7008` | `emission` | `active` | `error` | Output Path Is Reserved |
| `P7009` | `emission` | `active` | `error` | Build Is Already In Progress |
| `P7010` | `emission` | `active` | `error` | Source Map Could Not Be Written |
| `P7011` | `emission` | `active` | `error` | Build Manifest Does Not Match Configuration |
| `P7012` | `emission` | `active` | `error` | Build Output Has Been Modified |
| `P7013` | `emission` | `active` | `warning` | Previous Build Backup Could Not Be Removed |
| `P9001` | `internal` | `active` | `error` | Internal Compiler Error |
<!-- diagnostic-catalog:end -->
