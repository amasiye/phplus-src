<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Transpilation;

use Amasiye\Ppphp\Frontend\ParsedFile;
use Amasiye\Ppphp\Semantic\SemanticModel;
use Amasiye\Ppphp\Transpilation\Pass\Interfaces\TranspilationPass;
use Amasiye\Ppphp\Transpilation\Pass\LowerLocalDeclarationsPass;
use Amasiye\Ppphp\Transpilation\Pass\LowerLoopDeclarationsPass;

final readonly class PhpLowerer
{
    /** @var list<TranspilationPass> */
    private array $passes;

    /** @param list<TranspilationPass>|null $passes */
    public function __construct(?array $passes = null)
    {
        $this->passes = $passes ?? [
            new LowerLocalDeclarationsPass(),
            new LowerLoopDeclarationsPass(),
        ];
    }

    public function lower(ParsedFile $parsedFile, SemanticModel $semanticModel): GeneratedPhp
    {
        if ($semanticModel->parsedFile !== $parsedFile) {
            throw new \InvalidArgumentException('The semantic model must belong to the file being lowered.');
        }

        if (!$semanticModel->isSuccessful) {
            throw new \LogicException('A file with semantic errors cannot be lowered.');
        }

        $context = new TranspilationContext($parsedFile, $semanticModel);

        foreach ($this->passes as $pass) {
            $pass->execute($context);
        }

        return $context->generate();
    }
}
