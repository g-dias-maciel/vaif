Type: grilling
Status: open
Blocked by: none

## Question

What frontmatter/metadata schema should blog posts use?

Blog posts are written as markdown files by AI, reviewed by a human, and rendered to HTML by PHP. Each post needs metadata for:
- **Rendering**: title, date, author, featured image
- **SEO**: meta description, canonical URL, OG image
- **Organization**: tags/categories, slug
- **AI search**: structured data fields that help LLMs index the content

What format? YAML frontmatter (like Jekyll/Hugo) is the standard for markdown files, but the PHP parser needs to support it.

What fields are required vs optional? What validation should the rendering code enforce? How do tags and categories work — freeform or predefined lists?
