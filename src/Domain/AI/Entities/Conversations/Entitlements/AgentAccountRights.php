<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Entitlements;

/**
 * The account rights of the agent's own tools, named by the package. Each MCP package of this package that needs one
 * carries it as `#[MCPRequiresAccountRights(...)]`, and its skill names the same right in `requiredAccountRights`.
 * An application grants them: by roles in the config `AI.accountRights` ({@see AgentRoleMappedAccountRights}, the
 * default of {@see \DDD\Domain\AI\Services\Conversations\Entitlements\AIConversationEntitlementsService}), or by its own
 * {@see AgentAccountRightsInterface}. A right no mapping names is not held.
 *
 * Other packages declare their rights the same way (`CloudDriveAccountRights::USE_DRIVE`, the text tools'
 * `MANAGE_TEXTS`); {@see self::PLATFORM_RIGHTS_OF_OTHER_PACKAGES} names the ones the default mapping gives to the
 * platform operators.
 */
class AgentAccountRights
{
    /** @var string Read and change the platform itself: health, workers, crons, error groups (`System.*`) */
    public const string OPERATE_PLATFORM = 'operatePlatform';

    /** @var string Schedule tasks the agent runs on its own (`Agent.Tasks`) */
    public const string SCHEDULE_AGENT_TASKS = 'scheduleAgentTasks';

    /** @var string Keep and recall the agent's long-term memory (`Agent.Memory`) */
    public const string USE_AGENT_MEMORY = 'useAgentMemory';

    /** @var string Keep the owner's goals and the agent's commitments (`Agent.Goals`) */
    public const string USE_AGENT_GOALS = 'useAgentGoals';

    /** @var string Research the live web (`Agent.Web.Search`) */
    public const string USE_WEB = 'useWeb';

    /** @var string Read the user's UI, navigate and point at elements (`Agent.UI`) */
    public const string USE_UI = 'useUI';

    /** @var string Edit texts of records and the application in every language (ddd-common-translations) */
    public const string MANAGE_TEXTS = 'manageTexts';

    /** @var string Run commands in the agent's sandbox (ddd-agent-sandbox) */
    public const string USE_SANDBOX = 'useSandbox';

    /** @var string Drive a browser session (ddd-agent-browser) */
    public const string USE_BROWSER = 'useBrowser';

    /** @var string[] The rights of this package's tools that read or change the platform: operators only */
    public const array OPERATOR_RIGHTS = [self::OPERATE_PLATFORM];

    /** @var string[] The rights of this package's everyday tools: every account that may talk to the agent */
    public const array EVERYDAY_RIGHTS = [self::SCHEDULE_AGENT_TASKS, self::USE_AGENT_MEMORY, self::USE_AGENT_GOALS, self::USE_WEB, self::USE_UI];

    /** @var string[] Rights other packages declare that the default mapping gives to the platform operators */
    public const array PLATFORM_RIGHTS_OF_OTHER_PACKAGES = [self::MANAGE_TEXTS, self::USE_SANDBOX, self::USE_BROWSER];

    /** @var string[] Every right of this package's tools */
    public const array RIGHTS = [...self::OPERATOR_RIGHTS, ...self::EVERYDAY_RIGHTS];
}
