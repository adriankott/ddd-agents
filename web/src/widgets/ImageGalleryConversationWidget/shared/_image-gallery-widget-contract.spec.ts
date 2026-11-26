import { readFileSync } from "node:fs";
import { join } from "node:path";
import { describe, expect, it } from "vitest";
import {
  buildImageGalleryDeclineSentence,
  buildImageGalleryPickSentence,
  parseImageGalleryPayload,
} from "./image-gallery-payload";

/**
 * The frontend half of `image-gallery-widget-contract.json`: every example block parses, and every example sentence is
 * the one the widget posts today for its letters (`ImageGallery.tsx` sends exactly these builders). The backend reader
 * (`ImageGalleryConversationWidget::readAnswer()`, `ImageGalleryConversationWidgetTest`) reads the very same file.
 */
interface ImageGalleryContractReaction {
  exampleBlock: number;
  pickedLetters?: string[];
  declined?: boolean;
  text: string;
}

const contract: { wireName: string; exampleBlocks: string[]; exampleReactions: ImageGalleryContractReaction[] } =
  JSON.parse(readFileSync(join(__dirname, "..", "image-gallery-widget-contract.json"), "utf8"));
const widgetSource = readFileSync(join(__dirname, "..", "ImageGallery.tsx"), "utf8");

const readFenceFields = (exampleBlock: string): Record<string, unknown> => {
  const fenceMatch = /^```widget\n([\s\S]*)\n```$/.exec(exampleBlock);
  expect(fenceMatch).not.toBeNull();
  return JSON.parse(fenceMatch![1]) as Record<string, unknown>;
};

describe("image-gallery-widget-contract.json — the ImageGallery pick sentences", () => {
  it("names the widget", () => {
    expect(contract.wireName).toBe("ImageGallery");
  });

  it("is what the widget posts: the two builders through injectSignal", () => {
    expect(widgetSource).toContain("const result = await injectSignal(sentence);");
    expect(widgetSource).toContain("send(buildImageGalleryDeclineSentence(gallery.name)");
    expect(widgetSource).toContain("send(buildImageGalleryPickSentence(gallery.name, selectedFiles)");
  });

  contract.exampleBlocks.forEach((exampleBlock, exampleBlockNumber) => {
    it(`example block ${exampleBlockNumber} is an ImageGallery fence the payload parser reads`, () => {
      const { type, ...fields } = readFenceFields(exampleBlock);
      expect(type).toBe("ImageGallery");
      const gallery = parseImageGalleryPayload(JSON.stringify(fields));
      expect(gallery).not.toBeNull();
      expect(gallery!.files.map((file) => file.reference)).toEqual(
        (fields.files as { reference: string }[]).map((file) => file.reference),
      );
    });
  });

  contract.exampleReactions.forEach((exampleReaction, exampleReactionNumber) => {
    it(`sentence ${exampleReactionNumber} is what the widget posts`, () => {
      const { type: _type, ...fields } = readFenceFields(contract.exampleBlocks[exampleReaction.exampleBlock]);
      const gallery = parseImageGalleryPayload(JSON.stringify(fields))!;
      const sentence = exampleReaction.declined
        ? buildImageGalleryDeclineSentence(gallery.name)
        : buildImageGalleryPickSentence(
            gallery.name,
            gallery.files.filter((file) => exampleReaction.pickedLetters!.includes(file.letter)),
          );
      expect(sentence).toBe(exampleReaction.text);
    });
  });
});
