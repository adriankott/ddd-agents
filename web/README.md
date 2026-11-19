# @mgamadeus/ddd-agents

React frontend of [mgamadeus/ddd-agents](https://github.com/ddd-framework/ddd-agents), the agentic loop for backends
built with mgamadeus/ddd: the agent chat, the widget renderer and the composed-view kit. Released together with the
Composer package of the same version.

While the package is 0.x, a minor release may break: pin `~0.1.0` or the exact version.

```bash
npm install @mgamadeus/ddd-agents@~0.1.1 @types/google.maps@^3.55.0 apexcharts@^3.41.0 dompurify@^3.4.0 \
  highlight.js@^11.12.0 katex@^0.19.0 mermaid@^12.2.0 react-apexcharts@'>=1.4.0 <1.6.0' react-markdown@^10.1.0 \
  remark-directive@^4.0.0 remark-gfm@^4.0.1 remark-parse@^11.0.0 unified@^11.0.5 unist-util-visit@^5.0.0
```

The second part is the peer set (React 19 assumed present; `axios` ^0.28.0 only for the optional axios client).
`react-apexcharts` stays below 1.6.0: from 1.6.0 it needs apexcharts 4, the package draws with apexcharts 3.
The agent skills come with the Composer package (`vendor/mgamadeus/ddd-agents/.claude/skills`), not with this one.

Entries: `@mgamadeus/ddd-agents` (provider and setup), `/provider` (the provider alone), `/chat`, `/renderer`, `/widgets`, `/kit`, `/contract`,
`/client`, `/client/testing`, `/preview`, `/image-render`, and the stylesheet `@mgamadeus/ddd-agents/styles.css`
(import it once). Non-code files (widget documentation, preview fixtures, contracts, the node schema, the
sandbox frame, SCSS tokens) are exported one subpath per file at their path below `src`. React 19 and the libraries listed under `peerDependencies` come from the application.
