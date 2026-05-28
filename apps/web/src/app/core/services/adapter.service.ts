import { Injectable } from '@angular/core';
import { BaseModel } from '../models/base.model';

/**
 * AdapterService — maps API responses (snake_case) to app models (camelCase).
 *
 * NOTE: This adapter is needed if the backend sends snake_case JSON.
 * If backend already returns camelCase, this service may not be needed.
 *
 * BLOCKED: Requires backend to return id, createdAt, updatedAt in responses.
 */

/**
 * Transform snake_case keys to camelCase
 */
function toCamelCase<T>(obj: Record<string, unknown>): T {
  if (obj === null || typeof obj !== 'object') {
    return obj as T;
  }

  if (Array.isArray(obj)) {
    return obj.map(item => toCamelCase(item as Record<string, unknown>)) as T;
  }

  const result: Record<string, unknown> = {};

  for (const [key, value] of Object.entries(obj)) {
    const camelKey = key.replace(/_([a-z])/g, (_, letter) => letter.toUpperCase());
    result[camelKey] = toCamelCase(value as Record<string, unknown>);
  }

  return result as T;
}

/**
 * Transform camelCase keys to snake_case
 */
function toSnakeCase<T>(obj: Record<string, unknown>): T {
  if (obj === null || typeof obj !== 'object') {
    return obj as T;
  }

  if (Array.isArray(obj)) {
    return obj.map(item => toSnakeCase(item as Record<string, unknown>)) as T;
  }

  const result: Record<string, unknown> = {};

  for (const [key, value] of Object.entries(obj)) {
    const snakeKey = key.replace(/[A-Z]/g, letter => `_${letter.toLowerCase()}`);
    result[snakeKey] = toSnakeCase(value as Record<string, unknown>);
  }

  return result as T;
}

@Injectable({ providedIn: 'root' })
export class AdapterService {
  /**
   * Transform API response to camelCase (from snake_case)
   */
  transformToCamelCase<T>(data: unknown): T {
    if (Array.isArray(data)) {
      return data.map(item => toCamelCase(item as Record<string, unknown>)) as T;
    }
    return toCamelCase(data as Record<string, unknown>) as T;
  }

  /**
   * Transform request body to snake_case (from camelCase)
   */
  transformToSnakeCase<T>(data: T): Record<string, unknown> {
    return toSnakeCase(data as Record<string, unknown>);
  }

  /**
   * Apply common BaseModel fields if missing from API response.
   * BLOCKED: Requires backend to return id, createdAt, updatedAt
   */
  ensureBaseModel<T extends BaseModel>(entity: T): T {
    const now = new Date().toISOString();

    return {
      ...entity,
      id: entity.id || '',
      createdAt: entity.createdAt || now,
      updatedAt: entity.updatedAt || now,
    };
  }
}