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
    case ProjectSourceDiscoveryFailed = 'P0023';
    case SelectedPathExcluded = 'P0024';
    case SelectedPathNotReadable = 'P0025';
    case InvalidPhpSyntax = 'P1001';
    case ExplicitSourceFileRequired = 'P1002';
    case DirectoryCompilationUnavailable = 'P1003';
    case UnsupportedSourceFile = 'P1004';
    case SourceFileOutsideConfiguredRoots = 'P1005';
    case SourceFileNotReadable = 'P1006';
    case PhpSourceIsNotBuildTarget = 'P1007';
    case InvalidExtensionSyntax = 'P1008';
    case UnsupportedExtensionSyntax = 'P1009';
    case ExtensionNormalizationFailed = 'P1010';
    case TypedLocalSyntaxNotActive = 'P2001';
    case GenericSyntaxNotActive = 'P3001';
    case ThrowsSyntaxNotActive = 'P4001';
    case WhenSyntaxNotActive = 'P5001';
    case InvalidComposerConfiguration = 'P6001';
    case InvalidComposerAutoloadMapping = 'P6002';
    case InvalidInstalledComposerMetadata = 'P6003';
    case ConfiguredStubPathInvalid = 'P6004';
    case GeneratedPhpCouldNotBeWritten = 'P7001';
    case OutputPathCollision = 'P7002';
    case InternalCompilerError = 'P9001';
}
