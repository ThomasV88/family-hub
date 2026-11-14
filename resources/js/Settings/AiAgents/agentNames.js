// Agent display names are proper nouns and are not translated. An unknown key renders as the key itself.
export const AGENT_NAMES = { codex: 'OpenAI Codex', copilot: 'GitHub Copilot' };

export const agentName = (key) => AGENT_NAMES[key] ?? String(key);
