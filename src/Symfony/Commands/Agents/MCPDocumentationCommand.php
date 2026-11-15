<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\AI\Entities\Discovery\AgentSourceRoots;
use DDD\Domain\AI\Entities\MCPPackages\MCPBundle;
use DDD\Domain\AI\Entities\MCPPackages\MCPClassCatalog;
use DDD\Domain\AI\Entities\MCPPackages\MCPPackage;
use DDD\Domain\AI\Entities\MCPPackages\ToolResult;
use DDD\Domain\AI\Services\Conversations\Discovery\AgentSourceRootsService;
use DDD\Domain\AI\Services\Skills\MCPClassCatalogService;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Domain\Common\Services\AccountsService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Presentation\Mcp\Services\MCPToolExecutorService;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * The console twin of the `…/documentation` route of an MCP controller ({@see \DDD\Presentation\Mcp\MCPController}),
 * which answers `$bundle::buildDocumentation(noCache: …)->toJSON()`: prints the tool list and the generated JSON
 * schemas of a bundle or a package, so that a developer writing `#[MCPTool]` methods (docblocks, `@example`, the
 * reflected input schemas) reads the generated documentation without an HTTP round-trip. Rebuilds fresh by default
 * (`--cache` reads the cache).
 *
 * It also reports the token cost (rough estimate, about 4 characters per token):
 *   - the documentation: what the model reads to know the tools, per tool and in total;
 *   - `--call` executes a tool in-process, through the executor the agent uses, and measures its response (the
 *     tool-result cost a conversation carries on every turn).
 *
 *   php bin/console app:mcp:documentation --bundle=DocumentsMCPBundle
 *   php bin/console app:mcp:documentation --package=DocumentSearchMCPPackage   # one package, before it is bundled
 *   php bin/console app:mcp:documentation --list                               # every bundle and package found
 *   php bin/console app:mcp:documentation --call=searchDocuments --agentSpaceId=12
 *   php bin/console app:mcp:documentation --call=searchDocuments --agentSpaceId=12 --arguments='{"query":"invoice"}'
 *   php bin/console app:mcp:documentation --call=findAgentConversations --asAdminAccountId=<admin account id>
 *
 * The bundles and packages are found below `Presentation/Mcp` of the source roots {@see getMcpClassSourceRoots()}
 * names ({@see MCPClassCatalogService}). A `--call` runs as the owner of the space {@see resolveToolCallAgentSpace()}
 * resolves, with the space on the call's provenance (a tool that fills its arguments from the space, or resolves its
 * target from it, works as in a conversation), or as the admin account `--asAdminAccountId` names (an admin-audience
 * tool; the executor's admin gate decides). Around the tool call for a space an application measures the call through
 * {@see startToolCallMeasurement()} and {@see reportToolCallMeasurement()}.
 */
#[AsCommand(
    name: 'app:mcp:documentation',
    description: 'Print the generated MCP documentation (tools + JSON-Schemas) for a bundle or package — CLI twin of the /documentation endpoint.'
)]
class MCPDocumentationCommand extends AgentConsoleCommand
{
    /** @var string The option that runs a tool call as an admin account */
    public const string OPTION_AS_ADMIN_ACCOUNT_ID = 'asAdminAccountId';

    /** @var int The characters one token is estimated at */
    public const int CHARACTERS_PER_TOKEN = 4;

    /** @var int The tile edge in pixels of the vision-token estimate of an image */
    public const int VISION_TILE_EDGE_PIXELS = 768;

    /** @var int The tokens of one vision tile */
    public const int VISION_TOKENS_PER_TILE = 258;

    protected function configure(): void
    {
        $this
            ->addOption('bundle', null, InputOption::VALUE_REQUIRED, 'MCPBundle class (FQCN or short name) to document')
            ->addOption('package', null, InputOption::VALUE_REQUIRED, 'MCPPackage class (FQCN or short name) to document on its own')
            ->addOption('list', null, InputOption::VALUE_NONE, 'List all discoverable MCP bundles and packages, then exit')
            ->addOption('cache', null, InputOption::VALUE_NONE, 'Use the documentation cache instead of rebuilding fresh (default: rebuild)')
            ->addOption('call', null, InputOption::VALUE_REQUIRED, 'Execute a tool in-process and report its response size/tokens (needs a space, or --' . self::OPTION_AS_ADMIN_ACCOUNT_ID . ')')
            ->addOption(self::OPTION_AS_ADMIN_ACCOUNT_ID, null, InputOption::VALUE_REQUIRED, 'For --call of an ADMIN-audience tool: run it as this admin account instead of a space\'s owner; no space is then needed')
            ->addOption('arguments', null, InputOption::VALUE_REQUIRED, 'JSON tool arguments for --call (an argument the space fills is filled when absent)')
            ->addOption('dump-images', null, InputOption::VALUE_OPTIONAL, 'For --call: write the IMAGE parts of the tool result (which the agent sees as pictures, not as text) into this directory as <tool>-<n>.<ext>', false);
        $this->configureAgentSpaceOptions();
        $this->configureIgnoreBudgetOption();
    }

    /**
     * Lists, documents or calls, as the options ask.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function executeConsoleWork(InputInterface $input, OutputInterface $output): int
    {
        // the kernel, the container and a real tool's working set exceed the default CLI memory; a tool that
        // regenerates a large aggregate needs more still
        ini_set('memory_limit', '2048M');
        $mCPClassCatalog = $this->findMCPClassCatalog();

        if ((bool)$input->getOption('list')) {
            return $this->renderList($mCPClassCatalog, $output);
        }
        if ($input->getOption('call') !== null) {
            return $this->executeToolCallFromInput($input, $output, $mCPClassCatalog);
        }
        return $this->renderDocumentationFromInput($input, $output, $mCPClassCatalog);
    }

    /**
     * The source roots whose `Presentation/Mcp` folders hold the bundles and packages the command lists, documents
     * and calls. The default: the roots of the discovery contract ({@see AgentSourceRootsService}) — the
     * application's `src/` and the source path of every installed DDD module.
     *
     * @return AgentSourceRoots
     */
    protected function getMcpClassSourceRoots(): AgentSourceRoots
    {
        /** @var AgentSourceRootsService $agentSourceRootsService */
        $agentSourceRootsService = DDDService::instance()->getService(AgentSourceRootsService::class);
        return $agentSourceRootsService->getSourceRoots();
    }

    /**
     * The space a `--call` runs for; null = the input names none (the call is refused). Runs with the entity rights
     * lifted: the console names a space whoever is authenticated. The default: the space of `--agentSpaceId` or of
     * the options the agent-spaces service declares ({@see resolveAgentSpaceFromInput()}).
     *
     * @param InputInterface $input
     * @return AgentSpace|null
     * @throws InvalidOptionException when the input names a space that cannot be read
     */
    protected function resolveToolCallAgentSpace(InputInterface $input): ?AgentSpace
    {
        return $this->resolveAgentSpaceFromInput($input);
    }

    /**
     * What a `--call` without a space needs, as the refusal names it ("--call needs <this> (or --asAdminAccountId for
     * an admin tool)."). The default names the space options of the command.
     *
     * @return string
     */
    protected function describeToolCallScopeRequirement(): string
    {
        return 'a space: --' . implode(' or --', [self::OPTION_AGENT_SPACE_ID, ...$this->agentSpaceScopeOptionNames]);
    }

    /**
     * The space of a `--call` as the refusal names it when its owner account cannot be found ("Could not resolve
     * account from <this>.").
     *
     * @param AgentSpace $agentSpace
     * @return string
     */
    protected function describeToolCallAgentSpace(AgentSpace $agentSpace): string
    {
        return 'space ' . ($agentSpace->id ?? '?');
    }

    /**
     * `--bundle` or `--package`: prints the documentation JSON and its token estimate.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @param MCPClassCatalog $mCPClassCatalog
     * @return int
     */
    protected function renderDocumentationFromInput(InputInterface $input, OutputInterface $output, MCPClassCatalog $mCPClassCatalog): int
    {
        $bundleName = $input->getOption('bundle');
        $packageName = $input->getOption('package');
        if ($bundleName === null && $packageName === null) {
            $output->writeln('<error>Provide --bundle=… , --package=… , --call=… , or --list.</error>');
            return Command::INVALID;
        }
        $rebuildsFresh = !(bool)$input->getOption('cache');
        try {
            if ($bundleName !== null) {
                /** @var class-string<MCPBundle> $bundleClassName */
                $bundleClassName = $this->resolveMCPClassName((string)$bundleName, MCPBundle::class, $mCPClassCatalog->findBundleClassNameByShortName((string)$bundleName));
                $documentationJson = $bundleClassName::buildDocumentation(noCache: $rebuildsFresh)->toJSON();
            } else {
                /** @var class-string<MCPPackage> $packageClassName */
                $packageClassName = $this->resolveMCPClassName((string)$packageName, MCPPackage::class, $mCPClassCatalog->findPackageClassNameByShortName((string)$packageName));
                $documentationJson = $packageClassName::buildDocumentation(noCache: $rebuildsFresh)->toJSON();
            }
        } catch (Throwable $documentationError) {
            $output->writeln('<error>' . $documentationError->getMessage() . '</error>');
            return Command::FAILURE;
        }
        $output->writeln($this->buildPrettyJson($documentationJson));
        $this->renderDocumentationTokenReport($documentationJson, $output);
        return Command::SUCCESS;
    }

    /**
     * `--call`: runs the tool as the admin account of `--asAdminAccountId`, else as the owner of the call's space.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @param MCPClassCatalog $mCPClassCatalog
     * @return int
     */
    protected function executeToolCallFromInput(InputInterface $input, OutputInterface $output, MCPClassCatalog $mCPClassCatalog): int
    {
        $toolName = (string)$input->getOption('call');
        $adminAccountIdValue = $input->getOption(self::OPTION_AS_ADMIN_ACCOUNT_ID);
        if ($adminAccountIdValue !== null && !ctype_digit((string)$adminAccountIdValue)) {
            $output->writeln('<error>--' . self::OPTION_AS_ADMIN_ACCOUNT_ID . ' must be numeric.</error>');
            return Command::INVALID;
        }
        $toolArguments = $this->parseToolArgumentsFromInput($input);
        if ($toolArguments === null) {
            $output->writeln('<error>--arguments must be a JSON object.</error>');
            return Command::INVALID;
        }
        if ($adminAccountIdValue !== null) {
            return $this->executeToolCallAsAdminAccount($toolName, (int)$adminAccountIdValue, $toolArguments, $mCPClassCatalog, $output);
        }
        return $this->executeToolCallForAgentSpace($toolName, $input, $toolArguments, $mCPClassCatalog, $output);
    }

    /**
     * The decoded `--arguments`: [] when the option is absent, null when it is no JSON object.
     *
     * @param InputInterface $input
     * @return array<array-key, mixed>|null
     */
    protected function parseToolArgumentsFromInput(InputInterface $input): ?array
    {
        $argumentsJson = $input->getOption('arguments');
        if ($argumentsJson === null) {
            return [];
        }
        $toolArguments = json_decode((string)$argumentsJson, true);
        return is_array($toolArguments) ? $toolArguments : null;
    }

    /**
     * Runs an admin-audience tool in-process as the given admin account and reports its response. The executor's admin
     * gate decides: an account that is not an admin with access to all resources gets the refusal.
     *
     * @param string $toolName
     * @param int $adminAccountId
     * @param array<array-key, mixed> $toolArguments
     * @param MCPClassCatalog $mCPClassCatalog
     * @param OutputInterface $output
     * @return int
     */
    protected function executeToolCallAsAdminAccount(string $toolName, int $adminAccountId, array $toolArguments, MCPClassCatalog $mCPClassCatalog, OutputInterface $output): int
    {
        try {
            $adminAccount = $this->findAccountWithEntityRightsLifted($adminAccountId);
            if ($adminAccount === null) {
                $output->writeln("<error>No account $adminAccountId.</error>");
                return Command::FAILURE;
            }
            $this->actAsAccount($adminAccount);
            $mCPToolExecutorService = $this->getMCPToolExecutorService();
            $toolResult = $mCPToolExecutorService->execute($toolName, $toolArguments, $mCPClassCatalog->packageClassNames);
        } catch (Throwable $toolCallError) {
            $output->writeln('<error>' . $toolCallError->getMessage() . '</error>');
            return Command::FAILURE;
        }
        return $this->renderToolResult($toolName, $toolResult, null, $output);
    }

    /**
     * Runs a tool in-process as the owner of the call's space, with the space on the provenance (the path of a tool
     * call in a conversation), and reports its response.
     *
     * @param string $toolName
     * @param InputInterface $input
     * @param array<array-key, mixed> $toolArguments
     * @param MCPClassCatalog $mCPClassCatalog
     * @param OutputInterface $output
     * @return int
     */
    protected function executeToolCallForAgentSpace(string $toolName, InputInterface $input, array $toolArguments, MCPClassCatalog $mCPClassCatalog, OutputInterface $output): int
    {
        try {
            DDDService::instance()->deactivateEntityRightsRestrictions();
            try {
                $agentSpace = $this->resolveToolCallAgentSpace($input);
                $ownerAccount = $agentSpace !== null ? $this->findOwnerAccountOfAgentSpace($agentSpace) : null;
            } finally {
                DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
            }
        } catch (InvalidOptionException $invalidScopeError) {
            $output->writeln('<error>' . $invalidScopeError->getMessage() . '</error>');
            return Command::INVALID;
        } catch (Throwable $scopeError) {
            $output->writeln('<error>' . $scopeError->getMessage() . '</error>');
            return Command::FAILURE;
        }
        if ($agentSpace === null) {
            $output->writeln('<error>--call needs ' . $this->describeToolCallScopeRequirement() . ' (or --' . self::OPTION_AS_ADMIN_ACCOUNT_ID . ' for an admin tool).</error>');
            return Command::INVALID;
        }
        if ($ownerAccount === null) {
            $output->writeln('<error>Could not resolve account from ' . $this->describeToolCallAgentSpace($agentSpace) . '.</error>');
            return Command::FAILURE;
        }
        $this->actAsAccount($ownerAccount);
        try {
            $mCPToolExecutorService = $this->getMCPToolExecutorService();
            $agentToolCallProvenance = new AgentToolCallProvenance(agentSpace: $agentSpace);
            $this->startToolCallMeasurement($toolName);
            $toolResult = $mCPToolExecutorService->execute($toolName, $toolArguments, $mCPClassCatalog->packageClassNames, null, $agentToolCallProvenance);
            $this->reportToolCallMeasurement($toolName, $output);
        } catch (Throwable $toolCallError) {
            $output->writeln('<error>' . $toolCallError->getMessage() . '</error>');
            return Command::FAILURE;
        }
        $dumpImagesDirectory = $input->getOption('dump-images');
        return $this->renderToolResult($toolName, $toolResult, is_string($dumpImagesDirectory) && $dumpImagesDirectory !== '' ? $dumpImagesDirectory : null, $output);
    }

    /**
     * Override point: called right before the executor runs the tool of a `--call` for a space — where an application
     * starts a measurement of the call (a memory or time profile). The default: nothing.
     *
     * @param string $toolName
     * @return void
     */
    protected function startToolCallMeasurement(string $toolName): void
    {
    }

    /**
     * Override point: called right after the executor returned the result of a `--call` for a space, before the result
     * is printed — where an application stops the measurement {@see startToolCallMeasurement()} started and prints it.
     * Not called when the tool call throws: the error is printed and the run ends with FAILURE. An error thrown here is
     * printed the same way. The default: nothing.
     *
     * @param string $toolName
     * @param OutputInterface $output
     * @return void
     */
    protected function reportToolCallMeasurement(string $toolName, OutputInterface $output): void
    {
    }

    /**
     * Prints the tool's response, one line per image part (written to `$dumpImagesDirectory` when given), the peak
     * memory and the size estimate.
     *
     * @param string $toolName
     * @param ToolResult $toolResult
     * @param string|null $dumpImagesDirectory
     * @param OutputInterface $output
     * @return int FAILURE when the tool reported an error
     */
    protected function renderToolResult(string $toolName, ToolResult $toolResult, ?string $dumpImagesDirectory, OutputInterface $output): int
    {
        $output->writeln($toolResult->content);
        // the executor splits the image parts off the text (they reach the model as pictures): they are reported, and
        // written on request so that a tool's picture can be looked at
        foreach ($toolResult->images as $imageIndex => $toolResultImage) {
            $imageLine = sprintf(
                '<info>image %d:</info> %s %dx%d px, %s bytes, ≈%d vision tokens (768 px tiles)',
                $imageIndex + 1,
                $toolResultImage->mimeType,
                $toolResultImage->width,
                $toolResultImage->height,
                number_format($toolResultImage->byteSize),
                $this->estimateVisionTokens($toolResultImage->width, $toolResultImage->height)
            );
            if ($dumpImagesDirectory !== null) {
                $imageLine .= ' → ' . $this->writeImageFile($dumpImagesDirectory, $toolName, $imageIndex + 1, $toolResultImage->mimeType, $toolResultImage->base64Data);
            }
            $output->writeln($imageLine);
        }
        $output->writeln(sprintf('<info>PEAK MEMORY:</info> %s MB', number_format(memory_get_peak_usage(true) / 1048576, 1)));
        $output->writeln('');
        $output->writeln(sprintf(
            '<info>response:</info> %s bytes, ≈%s tokens (est ~4 chars/tok)%s',
            number_format(strlen($toolResult->content)),
            number_format($this->estimateTokens($toolResult->content)),
            $toolResult->isError ? '   <error>[tool reported an error]</error>' : ''
        ));
        return $toolResult->isError ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Writes one image part as `<directory>/<tool>-<number>.<extension>`.
     *
     * @param string $dumpImagesDirectory
     * @param string $toolName
     * @param int $imageNumber
     * @param string $mimeType
     * @param string $base64Data
     * @return string the path of the file
     */
    protected function writeImageFile(string $dumpImagesDirectory, string $toolName, int $imageNumber, string $mimeType, string $base64Data): string
    {
        if (!is_dir($dumpImagesDirectory)) {
            mkdir($dumpImagesDirectory, 0775, true);
        }
        $imagePath = rtrim($dumpImagesDirectory, '/') . '/' . $toolName . '-' . $imageNumber . '.' . (explode('/', $mimeType)[1] ?? 'img');
        file_put_contents($imagePath, base64_decode($base64Data));
        return $imagePath;
    }

    /**
     * Prints a per-tool and a total token estimate of a bundle or package documentation, so that the tool-definition
     * cost the model reads on every turn is visible. Prints nothing when the document is no JSON object.
     *
     * @param string $documentationJson
     * @param OutputInterface $output
     * @return void
     */
    protected function renderDocumentationTokenReport(string $documentationJson, OutputInterface $output): void
    {
        $documentation = json_decode($documentationJson, true);
        if (!is_array($documentation)) {
            return;
        }
        $output->writeln('');
        $output->writeln('<info>token estimate</info> (definitions, ~4 chars/tok):');
        $toolDocumentations = $documentation['tools'] ?? null;
        if (is_array($toolDocumentations)) {
            foreach ($toolDocumentations as $toolDocumentation) {
                if (!is_array($toolDocumentation)) {
                    continue;
                }
                $toolName = (string)($toolDocumentation['name'] ?? '?');
                $output->writeln(sprintf('  %-34s ≈%s tok', $toolName, number_format($this->estimateTokens((string)json_encode($toolDocumentation)))));
            }
        }
        $output->writeln(sprintf('  %-34s ≈%s tok', '── total (whole document)', number_format($this->estimateTokens($documentationJson))));
    }

    /**
     * A rough token estimate: {@see CHARACTERS_PER_TOKEN} characters per token.
     *
     * @param string $text
     * @return int
     */
    protected function estimateTokens(string $text): int
    {
        return (int)ceil(mb_strlen($text) / self::CHARACTERS_PER_TOKEN);
    }

    /**
     * The vision tokens of an image: one tile of {@see VISION_TOKENS_PER_TILE} tokens per started square of
     * {@see VISION_TILE_EDGE_PIXELS} pixels, at least one.
     *
     * @param int $widthPixels
     * @param int $heightPixels
     * @return int
     */
    protected function estimateVisionTokens(int $widthPixels, int $heightPixels): int
    {
        return max(1, (int)ceil($widthPixels / self::VISION_TILE_EDGE_PIXELS))
            * max(1, (int)ceil($heightPixels / self::VISION_TILE_EDGE_PIXELS))
            * self::VISION_TOKENS_PER_TILE;
    }

    /**
     * The class an option names: a fully qualified name as given, a short name as the catalogue found it.
     *
     * @param string $classNameOfOption the option's value
     * @param class-string $expectedParentClassName
     * @param string|null $catalogueClassName the catalogue's class of that short name, null when none
     * @return class-string
     * @throws RuntimeException when no such class exists or it is not of the expected kind
     */
    protected function resolveMCPClassName(string $classNameOfOption, string $expectedParentClassName, ?string $catalogueClassName): string
    {
        $resolvedClassName = str_contains($classNameOfOption, '\\') ? ltrim($classNameOfOption, '\\') : $catalogueClassName;
        if ($resolvedClassName === null || !class_exists($resolvedClassName)) {
            throw new RuntimeException("Class not found for '$classNameOfOption'. Use --list to see available classes.");
        }
        if (!is_subclass_of($resolvedClassName, $expectedParentClassName)) {
            throw new RuntimeException("$resolvedClassName is not a " . MCPClassCatalog::getShortClassName($expectedParentClassName) . '.');
        }
        return $resolvedClassName;
    }

    /**
     * Prints the bundles and the packages of the catalogue as two lists.
     *
     * @param MCPClassCatalog $mCPClassCatalog
     * @param OutputInterface $output
     * @return int
     */
    protected function renderList(MCPClassCatalog $mCPClassCatalog, OutputInterface $output): int
    {
        $output->writeln('<info>MCP Bundles</info> (use with --bundle):');
        foreach ($mCPClassCatalog->bundleClassNames as $bundleClassName) {
            /** @var class-string<MCPBundle> $bundleClassName */
            $output->writeln(sprintf('  %-30s %s', MCPClassCatalog::getShortClassName($bundleClassName), $bundleClassName::$description ?? ''));
        }
        $output->writeln('');
        $output->writeln('<info>MCP Packages</info> (use with --package):');
        foreach ($mCPClassCatalog->packageClassNames as $packageClassName) {
            /** @var class-string<MCPPackage> $packageClassName */
            $output->writeln(sprintf('  %-30s %s', MCPClassCatalog::getShortClassName($packageClassName), $packageClassName::$description ?? ''));
        }
        return Command::SUCCESS;
    }

    /**
     * The documentation JSON re-encoded with indentation; as given when it does not decode.
     *
     * @param string $documentationJson
     * @return string
     */
    protected function buildPrettyJson(string $documentationJson): string
    {
        $documentation = json_decode($documentationJson, true);
        if ($documentation === null) {
            return $documentationJson;
        }
        return (string)json_encode($documentation, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * The catalogue of the bundles and packages below the roots of {@see getMcpClassSourceRoots()}.
     *
     * @return MCPClassCatalog
     */
    protected function findMCPClassCatalog(): MCPClassCatalog
    {
        /** @var MCPClassCatalogService $mCPClassCatalogService */
        $mCPClassCatalogService = DDDService::instance()->getService(MCPClassCatalogService::class);
        return $mCPClassCatalogService->findMCPClassCatalog($this->getMcpClassSourceRoots());
    }

    /**
     * The account of `$accountId`, read with the entity rights lifted (the console names any account).
     *
     * @param int $accountId
     * @return Account|null
     */
    protected function findAccountWithEntityRightsLifted(int $accountId): ?Account
    {
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            /** @var AccountsService $accountsService */
            $accountsService = DDDService::instance()->getService(AccountsService::class);
            $account = $accountsService->find($accountId);
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
        return $account instanceof Account ? $account : null;
    }

    /**
     * The tool executor (the application's class, when it replaces it).
     *
     * @return MCPToolExecutorService
     */
    protected function getMCPToolExecutorService(): MCPToolExecutorService
    {
        /** @var MCPToolExecutorService $mCPToolExecutorService */
        $mCPToolExecutorService = DDDService::instance()->getService(MCPToolExecutorService::class);
        return $mCPToolExecutorService;
    }
}
