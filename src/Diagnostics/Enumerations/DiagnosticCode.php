<?php

declare(strict_types=1);

namespace Amasiye\Phplus\Diagnostics\Enumerations;

enum DiagnosticCode: string
{
    case ProjectConfigurationNotFound = 'P0001';
    case ProjectConfigurationNotReadable = 'P0002';
    case InvalidProjectConfigurationJson = 'P0003';
    case UnknownConfigurationProperty = 'P0004';
    case MissingConfigurationProperty = 'P0005';
    case InvalidConfigurationPropertyType = 'P0006';
    case UnsupportedTargetPhpVersion = 'P0007';
    case UnsafeProjectPath = 'P0008';
    case ProjectConfigurationAlreadyExists = 'P0009';
    case CompilerFrontendNotAvailable = 'P0010';
    case ProjectPathDoesNotExist = 'P0011';
    case ProjectPathNotDirectory = 'P0012';
    case ConfiguredPathsOverlap = 'P0013';
    case SourcePathDoesNotExist = 'P0014';
    case SourcePathNotDirectory = 'P0015';
    case FileOutsideProjectRoot = 'P0016';
    case ProjectCleanupFailed = 'P0017';
    case InputFileDoesNotExist = 'P0018';
    case InputPathNotFile = 'P0019';
    case InvalidOutputFormat = 'P0020';
    case ProjectInitializationFailed = 'P0021';
    case InvalidInvocation = 'P0022';
    case InternalCompilerError = 'P9001';
}
